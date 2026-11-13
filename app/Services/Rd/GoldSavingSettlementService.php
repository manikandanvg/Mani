<?php

namespace App\Services\Rd;

use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\MemberContract;
use App\Models\RdEntry;
use App\Models\RdSettlement;
use App\Models\RedeemableQr;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\Charges\ChargeResolver;
use App\Services\ContractSettlementService;
use App\Services\Qr\RedeemableQrService;
use App\Services\RdCollectionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gold Saving (G11 PLUS 1 / 2 / 3) settlement — Lord's board 2026-11-13.
 *
 * Never automatic: once the scheme date has expired Head Office settles it on the Settlements
 * page, because the saver must first hand back the 100 mg coins received during the scheme
 * (PLUS 1: every month's pieces, PLUS 2: the month-2 pieces).
 *
 *   coins back   a QR never redeemed is cancelled and counts as returned; redeemed coins are
 *                handed in at the settling branch and go into ITS stock; every coin not
 *                returned is deducted at the settlement day's 100 mg price.
 *   value        the dues actually paid (they differ month to month in PLUS 1), plus the bonus
 *                months at the FIRST month's due when earned, minus the missing coins.
 *   form         all dues paid → gold QR with bonus · 6 dues or more → gold QR, no bonus ·
 *                fewer than 6 → silver QR, no bonus.
 *
 * The QR is redeemed at the settlement day's price (it carries its own frozen price).
 */
class GoldSavingSettlementService
{
    /** Dues needed for a gold (rather than silver) settlement. */
    public const GOLD_MIN_DUES = 6;

    public static function isGoldSaving(MemberContract $contract): bool
    {
        return GoldSavingRules::variant($contract->plan) !== null;
    }

    public static function canSettle(MemberContract $contract, ?Carbon $today = null): bool
    {
        return self::isGoldSaving($contract)
            && $contract->bond_id !== null
            && $contract->settled_on === null
            && ! in_array($contract->status, ['closed', 'cancelled'], true)
            && ContractSettlementService::isExpired($contract, $today)
            && ! RdSettlement::where('bond_id', $contract->bond_id)->exists();
    }

    /**
     * What the settlement would be. $coinsReturned = coins physically handed back (null = all
     * the redeemed ones).
     *
     * @return array<string, mixed>
     */
    public function quote(MemberContract $contract, ?int $coinsReturned = null): array
    {
        $plan = $contract->plan;
        $bond = $contract->bond;
        abort_unless($bond && self::isGoldSaving($contract), 422, 'Not a Gold Saving contract.');

        $duesTotal = (int) ($plan->validity_months ?: 11);
        $duesPaid = min($duesTotal, RdCollectionService::duesPaid($bond));
        $first = GoldSavingRules::firstDue($bond);
        $paid = round($first + (float) RdEntry::where('bond_id', $bond->id)->sum('value'), 2);

        $full = $duesPaid >= $duesTotal;
        $metal = $duesPaid >= self::GOLD_MIN_DUES ? 'gold' : 'silver';
        $bonus = $full ? round((int) $plan->settlement_bonus_months * $first, 2) : 0.0;

        // 100 mg pieces handed out during the scheme
        $piece = (float) $plan->rd_qr_grams;
        $count = fn ($qrs) => $piece > 0 ? (int) round((float) $qrs->sum('gram_worth') / $piece) : 0;
        $qrs = RedeemableQr::where('bond_id', $bond->id)->whereIn('status', ['pending', 'redeemed'])->get();
        $unredeemed = $count($qrs->where('status', 'pending'));
        $redeemed = $count($qrs->where('status', 'redeemed'));

        $returned = max(0, min($redeemed, $coinsReturned ?? $redeemed));
        $missing = $redeemed - $returned;
        $coinPrice = $missing > 0 ? GoldSavingRules::unitPrice($plan) : 0.0;
        $deduction = round($missing * $coinPrice, 2);

        return [
            'dues_paid' => $duesPaid, 'dues_total' => $duesTotal, 'first_due' => $first, 'paid_total' => $paid,
            'bonus_months' => $full ? (int) $plan->settlement_bonus_months : 0, 'bonus' => $bonus, 'metal' => $metal,
            'coins_given' => $unredeemed + $redeemed, 'coins_cancelled' => $unredeemed, 'coins_redeemed' => $redeemed,
            'coins_returned' => $returned, 'coins_missing' => $missing, 'coin_price' => $coinPrice, 'deduction' => $deduction,
            'worth' => max(0.0, round($paid + $bonus - $deduction, 2)),
        ];
    }

    public function settle(MemberContract $contract, int $branchId, ?int $coinsReturned = null, ?string $note = null, ?int $userId = null, ?Carbon $today = null): RdSettlement
    {
        $today ??= Carbon::today();

        $row = DB::transaction(function () use ($contract, $branchId, $coinsReturned, $note, $userId, $today) {
            $contract = MemberContract::with(['plan', 'bond.plan'])->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::canSettle($contract, $today), 422, 'This Gold Saving contract cannot be settled: it has not expired, or it is already settled.');

            $q = $this->quote($contract, $coinsReturned);
            abort_if($q['worth'] <= 0, 422, 'Nothing to settle: the coins not returned are worth more than the dues paid.');
            $bond = $contract->bond;
            $plan = $contract->plan;

            // coins back: unredeemed QRs are cancelled; returned coins join the settling branch's stock
            RedeemableQr::where('bond_id', $bond->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            if ($q['coins_returned'] > 0) {
                $this->stockIn($plan, $branchId, $q['coins_returned'], $contract, $today, $userId);
            }

            $live = LiveRate::where('country', 'IN')->latest('effective_at')->first();
            $rate = (float) ($q['metal'] === 'silver' ? $live?->silver : $live?->gold);
            abort_if($rate <= 0, 422, 'No live ' . $q['metal'] . ' rate set — set it before settling.');
            $grams = $q['metal'] === 'gold'
                ? app(ChargeResolver::class)->gramsForWorth($q['worth'], $rate)
                : round($q['worth'] / 1.03 / $rate, 4);
            $qr = app(RedeemableQrService::class)->mintSettlementQr($bond, $q['worth'], $grams, $q['metal'], true);

            $row = RdSettlement::create([
                'member_contract_id' => $contract->id, 'bond_id' => $bond->id, 'member_id' => $contract->member_id ?: $bond->member_id,
                'branch_id' => $branchId, 'dues_paid' => $q['dues_paid'], 'dues_total' => $q['dues_total'],
                'first_due' => $q['first_due'], 'paid_total' => $q['paid_total'], 'bonus' => $q['bonus'],
                'coins_given' => $q['coins_given'], 'coins_cancelled' => $q['coins_cancelled'],
                'coins_returned' => $q['coins_returned'], 'coins_missing' => $q['coins_missing'],
                'coin_price' => $q['coin_price'], 'deduction' => $q['deduction'], 'worth' => $q['worth'],
                'metal' => $q['metal'], 'redeemable_qr_id' => $qr->id, 'settled_on' => $today->toDateString(),
                'note' => $note ? mb_substr($note, 0, 255) : null, 'created_by' => $userId,
            ]);

            $bond->update(['status' => 'closed']);
            $contract->update(['status' => 'closed', 'settled_on' => $today->toDateString()]);

            return $row;
        });

        // WhatsApp the settlement QR after commit — best effort, never undoes the settlement.
        if (! app()->runningUnitTests() && $row->qr) {
            try {
                app(RedeemableQrService::class)->deliver($row->qr, false);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $row;
    }

    /** Returned 100 mg coins → the settling branch's stock. */
    protected function stockIn($plan, int $branchId, int $coins, MemberContract $contract, Carbon $today, ?int $userId): void
    {
        $product = ($plan->rd_qr_product_id ? CatalogProduct::find($plan->rd_qr_product_id) : null)
            ?? CatalogProduct::where('material', 'gold')->where('default_weight', (float) $plan->rd_qr_grams)->orderBy('id')->first();
        abort_unless($product, 422, 'No catalogue item found for the ' . ((float) $plan->rd_qr_grams * 1000) . ' mg gold coin — returned coins cannot be stocked.');

        $stock = Stock::where('branch_id', $branchId)->where('catalog_product_id', $product->id)
            ->where(fn ($w) => $w->whereNull('order_line_id')->orWhere('order_line_id', 0))   // ordinary stock, not a customised piece
            ->lockForUpdate()->first()
            ?? Stock::create(['branch_id' => $branchId, 'catalog_product_id' => $product->id, 'quantity' => 0]);
        $stock->increment('quantity', $coins);

        StockMovement::create([
            'branch_id' => $branchId, 'catalog_product_id' => $product->id, 'type' => 'sales_return',
            'qty_change' => $coins, 'balance_after' => $stock->fresh()->quantity,
            'ref_type' => 'rd_settlement', 'ref_id' => $contract->id,
            'note' => 'Gold Saving settlement ' . $contract->contract_no . ': coins handed back',
            'moved_on' => $today->toDateString(), 'created_by' => $userId,
        ]);
    }
}
