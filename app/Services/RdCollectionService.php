<?php

namespace App\Services;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\RdEntry;
use App\Models\ResellerCommission;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Collects a single installment ("renewal") against an EXISTING RD / Gold-Saving bond —
 * the legacy admin/trade/rdsales flow. Opening a new RD happens in SalesService; this only
 * adds the monthly collection: a tbl_rdentry row, a cash-stock deduction, and the branch
 * bill-margin (excluding HQ).
 */
class RdCollectionService
{
    /** Head office: its own collections do not earn a branch bill-margin. */
    public const HQ_BRANCH_ID = 1;

    /** Redeemable QR minted by the latest collect(), delivered after commit. */
    protected ?int $pendingQrId = null;

    /** Bond of the latest collect(), for the post-commit contract re-send. */
    protected ?int $pendingBondId = null;

    /**
     * @param  array  $data  keys: bond_id, branch_id, amount, cash_stock_id?, paid_on?, created_by?
     */
    public function collect(array $data): RdEntry
    {
        $entry = DB::transaction(function () use ($data) {
            $bond = Bond::with('plan')->findOrFail($data['bond_id']);
            $plan = $bond->plan;
            $branchId = (int) $data['branch_id'];
            $paidOn = $data['paid_on'] ?? Carbon::now()->toDateString();
            $userId = $data['created_by'] ?? auth()->id();
            $amount = round((float) $data['amount'], 2);

            abort_unless($plan && $plan->type === 'rd', 422, 'Not a Gold Saving bond.');
            abort_unless($bond->status === 'active', 422, 'This bond is not active.');

            // G11 gold-QR plans (rd_qr_grams): the renewal amount is FIXED server-side the
            // legacy fetchrdsalesdetails way (see renewalQuote) — the branch cannot edit it.
            $quote = $this->renewalQuote($bond);
            $fixedAmount = $quote['total'] ?? null;
            // a bond with no first-month amount on record has nothing to fix against
            if ($fixedAmount !== null && $fixedAmount > 0) {
                $amount = $fixedAmount;
            }
            abort_unless($amount > 0, 422, 'Installment amount must be greater than zero.');

            $this->assertWithinRdLimit($bond, $plan, $amount, $fixedAmount !== null);

            // Currency context: the installment is collected in the BRANCH's currency;
            // the rate to INR is frozen on the entry (India = INR/1.0, unchanged).
            $branch = Branch::find($branchId);
            $fx = $branch?->fxRateToBase() ?? 1.0;
            $currency = $branch?->currency_code ?: 'INR';

            // Due number, legacy numbering (rdsales: rdcount + 2): due #1 is the joining /
            // Gold Saving registration payment, so the first renewal is due #2. Value-based,
            // not row-based: one entry of 3 × monthly covers dues N..N+2, so the next entry
            // starts at N+3 (a row count would wrongly restart at N+1).
            $dueCount = self::nextDueNumber($bond);

            $entry = RdEntry::create([
                'bond_id' => $bond->id,
                'member_id' => $bond->member_id,
                'paid_on' => $paidOn,
                'value' => $amount,
                'due_count' => $dueCount,
                'branch_id' => $branchId,
                'currency_code' => $currency,
                'fx_rate' => $fx,
            ]);

            if (! empty($data['cash_stock_id'])) {
                $this->deductCashStock((int) $data['cash_stock_id'], $branchId, $amount, $paidOn, $userId);
            }

            $this->payBillMargin($bond, $branchId, $userId, $amount, $paidOn, $currency, $fx);
            // Legacy Trade::rdsales (user 2026-11-04): every instalment pays the plan's IC
            // schedule again on the bond's billed value (epinvalue), EP-capped per upline.
            app(CommissionService::class)->issueInstantCommissionOn(
                $bond, (float) ($bond->epin_value ?: $bond->value), $paidOn, 'RD-' . $entry->id, $branchId
            );
            $this->mintRenewalQr($bond, $plan, $dueCount, $quote);
            $this->refreshContract($bond);

            return $entry;
        });

        // Post-commit: WhatsApp the updated contract (+ any renewal QR). Never inside the tx.
        $this->sendRenewalDocs();

        // Final-due acknowledgement (board 2026-08-11): month 1 was the joining payment,
        // so a scheme is fully paid once renewals cover validity − 1 dues.
        $bond = Bond::with('plan', 'member')->find($entry->bond_id);
        if ($bond && ! app()->runningUnitTests()) {
            $validity = (int) ($bond->plan?->validity_months ?: 11);
            if (self::duesCovered($bond) >= $validity - 1) {
                \App\Services\Push\Notifier::to($bond->member, 'rd',
                    'Scheme fully paid — congratulations!',
                    'All ' . $validity . ' months of your ' . ($bond->plan?->code ?? 'savings scheme')
                        . ' are complete. Your settlement benefits will follow as per your contract.',
                    route: '/contracts/' . $bond->id,
                );
            }
        }

        return $entry;
    }

    /**
     * The renewal amount of a Gold Saving bond — fixed, never editable (Lord's board 2026-11-13):
     *
     *   PLUS 1            pieces × today's 100 mg price incl. making/wastage/GST, a gold QR for
     *                     the pieces every month. Pieces are fixed at joining (bill ÷ that day's
     *                     100 mg price); a bond from before the rule: bond value ÷ ₹1,500.
     *   PLUS 2, month 2   pieces (joining ÷ ₹1,500) × today's 100 mg price, with the gold QR.
     *   PLUS 2 otherwise  the joining amount, no QR.
     *   PLUS 3            the joining amount, no QR.
     *
     * null = not a Gold Saving bond.
     *
     * @return array{mode: string, units: int, unit_price: float|null, total: float, breakup: array|null, qr: bool}|null
     */
    public function renewalQuote(Bond $bond): ?array
    {
        $plan = $bond->plan ?? $bond->load('plan')->plan;
        $variant = \App\Services\Rd\GoldSavingRules::variant($plan);
        if ($variant === null) {
            return null;
        }
        $units = \App\Services\Rd\GoldSavingRules::pieces($bond);
        $gold = $variant === \App\Services\Rd\GoldSavingRules::PLUS1
            || ($variant === \App\Services\Rd\GoldSavingRules::PLUS2 && self::nextDueNumber($bond) === 2);

        if (! $gold) {
            return ['mode' => 'bond_value', 'units' => $units, 'unit_price' => null,
                'total' => \App\Services\Rd\GoldSavingRules::firstDue($bond), 'breakup' => null, 'qr' => false];
        }

        $breakup = app(\App\Services\Qr\GoldQrPricing::class)->breakup($plan);

        return ['mode' => 'gold_units', 'units' => $units, 'unit_price' => $breakup['total'],
            'total' => round($breakup['total'] * $units, 2), 'breakup' => $breakup, 'qr' => true];
    }

    /** Old-system basis: one 100 mg piece per ₹1,500 of the bond value. */
    public const LEGACY_RD_UNIT_VALUE = \App\Services\Rd\GoldSavingRules::LEGACY_UNIT_VALUE;

    /**
     * Dues PAID on the bond, the registration payment included (user 2026-11-06:
     * registration + 2 renewals = 3 paid, 8 pending of 11).
     */
    public static function duesPaid(Bond $bond): int
    {
        return 1 + self::duesCovered($bond);
    }

    /** Due number the next renewal takes — registration is due #1 (legacy rdcount + 2). */
    public static function nextDueNumber(Bond $bond): int
    {
        return self::duesPaid($bond) + 1;
    }

    /**
     * How many monthly dues this bond's collections actually cover. Fixed-price
     * (gold-QR) plans: every entry is exactly one due, so count rows. Variable plans:
     * one entry may pay several months at once, so divide the rupee total by the
     * monthly baseline — 3 × monthly in one save = 3 dues covered.
     */
    public static function duesCovered(Bond $bond): int
    {
        $plan = $bond->plan ?? $bond->load('plan')->plan;

        $rows = RdEntry::where('bond_id', $bond->id);

        if ($plan && (float) $plan->rd_qr_grams > 0) {
            return $rows->count();      // fixed amount varies with the gold rate — count entries
        }

        $monthly = (float) $bond->value;
        if ($monthly <= 0) {
            return $rows->count();
        }

        return (int) round((float) $rows->sum('value') / $monthly);
    }

    /**
     * RD ceiling (schema v2 item 12): a bond can never collect beyond its plan's validity —
     * the joining payment is due #1, renewals fill the remaining validity − 1 dues. Every
     * renewal is exactly one due now (board 2026-11-13: no editable amount).
     */
    protected function assertWithinRdLimit(Bond $bond, $plan, float $amount, bool $fixed): void
    {
        $validity = (int) $plan->validity_months;
        if ($validity <= 0) {
            return;
        }
        abort_if(self::duesCovered($bond) + 1 > $validity - 1, 422,
            "Gold Saving limit reached: this {$validity}-month scheme has no dues left to renew.");
    }

    /**
     * Renewal gold QR: PLUS 1 every month, PLUS 2 in month 2 only — for the bond's 100 mg
     * pieces, worth what the saver paid that day and redeemed at that day's gold price.
     */
    protected function mintRenewalQr(Bond $bond, $plan, int $dueCount, ?array $quote): void
    {
        if (! $plan->is_redeem || $quote === null || empty($quote['qr']) || $quote['total'] <= 0) {
            return;
        }

        $this->pendingQrId = app(\App\Services\Qr\RedeemableQrService::class)
            ->mintSettlementQr($bond, $quote['total'], round((float) $plan->rd_qr_grams * $quote['units'], 4), 'gold', true)->id;
    }

    /**
     * Item 11: every renewal refreshes the contract body so the monthly chart shows the
     * real paid dates/amounts/branches; the updated PDF is re-sent after commit.
     */
    protected function refreshContract(Bond $bond): void
    {
        $this->pendingBondId = $bond->id;
        $contract = \App\Models\MemberContract::where('bond_id', $bond->id)->latest('id')->first();
        if ($contract) {
            $bond->setRelation('plan', $bond->plan);
            $contract->update(['content' => app(\App\Services\Contract\ContractContentBuilder::class)->build($bond)]);
        }
    }

    /** WhatsApp the updated contract (+ renewal QR if minted) — best-effort, skipped in tests. */
    protected function sendRenewalDocs(): void
    {
        [$qrId, $bondId] = [$this->pendingQrId, $this->pendingBondId];
        $this->pendingQrId = $this->pendingBondId = null;

        if (app()->runningUnitTests() || ! $bondId) {
            return;
        }

        try {
            if ($qrId && ($qr = \App\Models\RedeemableQr::find($qrId))) {
                app(\App\Services\Qr\RedeemableQrService::class)->deliver($qr);   // contract + QR

                return;
            }
            // Board 2026-08-11: acknowledgements go push+inbox (WhatsApp = OTP only).
            $bond = Bond::with('member', 'plan')->find($bondId);
            if ($bond?->member && $bond->plan?->is_contract) {
                \App\Services\Push\Notifier::to($bond->member, 'rd',
                    'Gold Saving payment received — contract updated',
                    'Dear ' . $bond->member->name . ', your renewal was recorded and your contract ' . $bond->invoice_no . ' has been updated. Tap to view.',
                    route: '/contracts/' . $bond->id,
                    data: [
                        'bond_id' => (string) $bond->id,
                        'contract_url' => app(\App\Services\Contract\ContractService::class)->store($bond),
                    ],
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Gold Saving renewal document send failed: ' . $e->getMessage());
        }
    }

    /** Cash stock is held as rupee value (CashStockSeeder), so we deduct the collected amount. */
    protected function deductCashStock(int $stockId, int $branchId, float $amount, string $paidOn, ?int $userId): void
    {
        $stock = Stock::where('id', $stockId)->where('branch_id', $branchId)->first();
        if (! $stock) {
            return;
        }
        $stock->decrement('quantity', $amount);
        StockMovement::create([
            'branch_id' => $branchId,
            'catalog_product_id' => $stock->catalog_product_id,
            'type' => 'sale',
            'qty_change' => -$amount,
            'balance_after' => $stock->fresh()->quantity,
            'ref_type' => 'rd_entry',
            'moved_on' => $paidOn,
            'created_by' => $userId,
        ]);
    }

    /**
     * Branch RENEWAL margin on the collection (legacy tbl_reseller_com), skipped for HQ.
     * Schema v2: renewals pay the plan's renewal_margin, not the sale billing_margin.
     */
    protected function payBillMargin(Bond $bond, int $branchId, ?int $userId, float $amount, string $paidOn, string $currency = 'INR', float $fx = 1.0): void
    {
        $plan = $bond->plan;
        if ($branchId === self::HQ_BRANCH_ID || ! $plan || (float) $plan->renewal_margin == 0.0) {
            return;
        }
        // Margin computes in the branch currency; the ledger stores INR base
        // (caps stay coherent), the branch balance shows its own currency —
        // same policy as SalesService::payBillMargin.
        $marginLocal = round($amount * (float) $plan->renewal_margin / 100, 2);
        if ($marginLocal <= 0) {
            return;
        }

        // EP filter (board 2026-08-11): capped at the branch distributor's headroom.
        $beneficiary = Branch::find($branchId)?->distributorUser?->memberAccount;
        $marginBase = app(\App\Services\CommissionService::class)
            ->capByEp($beneficiary, round($marginLocal * $fx, 2), $paidOn);
        if ($marginBase <= 0) {
            return;
        }
        $marginLocal = round($marginBase / $fx, 2);

        ResellerCommission::create([
            'bill_date' => $paidOn,
            'invoice_no' => 'RD-' . $bond->id . '-' . $bond->rdEntries()->count(),
            'com_type_id' => ResellerCommission::COM_RD_RENEWAL_MARGIN,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'com_value' => $marginBase,
            'currency_code' => $currency,
            'fx_rate' => $fx,
            'reference_member_id' => $bond->member_id,
            'status' => 'passed',
        ]);
        Branch::where('id', $branchId)->increment('bill_margin', $marginLocal);
    }
}
