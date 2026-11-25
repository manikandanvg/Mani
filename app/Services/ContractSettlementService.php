<?php

namespace App\Services;

use App\Models\ContractSettlement;
use App\Models\MemberContract;
use App\Models\MemberWallet;
use App\Services\Push\Notifier;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Generate settlement" on an EXPIRED contract (board phase 2, 2026-08-28).
 *
 * Head Office types the settlement value; it is credited to the distributor's cash
 * wallet immediately (no approval gate — the admin entering it IS the approval),
 * the contract closes, and the distributor gets a push + inbox line. The app shows
 * every settlement under My Earnings → Contract Settlement with the running wallet.
 */
class ContractSettlementService
{
    /** A contract is "expired" once its end date has passed or the engine parked it as matured. */
    public static function isExpired(MemberContract $contract, ?Carbon $today = null): bool
    {
        $today ??= Carbon::today();

        return $contract->status === 'matured'
            || ($contract->end_date !== null && $contract->end_date->lt($today));
    }

    public static function canGenerate(MemberContract $contract): bool
    {
        // force closed (user 2026-11-25): settled by its own closure settlement on the Settlements page
        if ($contract->status === 'force_closed') {
            return false;
        }
        // Gold-QR schemes (user 2026-11-15): the ADMIN decides when, and whether it is the interim
        // or the final settlement — open until the final one has been issued.
        if (self::settlesByQr($contract)) {
            return self::stageOptions($contract) !== [];
        }

        return self::isExpired($contract) && ! $contract->settlements()->exists();
    }

    /**
     * The contract's own branch — where it was billed (user 2026-11-19). A settlement QR is
     * redeemed only there (Head Office excepted) and returned Gold Saving coins join its stock.
     */
    public static function homeBranchId(MemberContract $contract): ?int
    {
        // "Who billed" (user 2026-11-19): the bond's billing branch, whatever its kind. A branch
        // that has since closed (inactive / removed) hands its settlements to Head Office.
        $id = (int) ($contract->bond?->branch_id ?: $contract->branch_id);
        if ($id && \App\Models\Branch::whereKey($id)->where('is_active', true)->exists()) {
            return $id;
        }

        return $id ? ((int) \App\Models\Branch::where('level', 'hq')->orderBy('id')->value('id') ?: null) : null;
    }

    public const STAGES = ['interim' => 'Interim settlement', 'final' => 'Final settlement (closes the contract)'];

    /**
     * What the admin may still issue on a gold-QR contract (user 2026-11-15 — "the choice is in
     * the admin's hand"): ONE interim settlement and ONE final settlement, in either the usual
     * order or the final alone, on any day the admin decides (the interim today and the final a
     * day later is fine). Nothing more once the final is issued or the contract is cancelled.
     *
     * @return array<string, string>
     */
    public static function stageOptions(MemberContract $contract): array
    {
        // NB: 'closed' alone does not block. An old-system contract is 'closed' just because it
        // EXPIRED ("Expired — not settled", e.g. LEG-27) — it is exactly the one waiting for its
        // settlement. What ends the journey is the FINAL settlement itself (or an earlier
        // settlement recorded without a stage).
        if (in_array($contract->status, ['cancelled', 'force_closed'], true)) {
            return [];
        }
        $rows = $contract->settlements()->get(['stage']);
        $done = $rows->pluck('stage')->all();
        if (in_array('final', $done, true) || $rows->contains(fn ($s) => $s->stage === null)) {
            return [];
        }
        $options = self::STAGES;
        if (in_array('interim', $done, true)) {
            unset($options['interim']);
        }

        return $options;
    }

    /** The stage pre-selected in the popup: interim while the contract still runs and none was given, else final. */
    public static function suggestedStage(MemberContract $contract, ?Carbon $today = null): ?string
    {
        $today ??= Carbon::today();
        $options = self::stageOptions($contract);
        if (isset($options['interim']) && $contract->end_date && $contract->end_date->gt($today)) {
            return 'interim';
        }

        return isset($options['final']) ? 'final' : (array_key_first($options) ?: null);
    }

    /**
     * The usual settlement dates of a gold-QR contract, shown in the popup as a GUIDE only: each
     * settlement cycle (the 12th month of a 24-month contract) and the contract's end.
     *
     * @return array<int, Carbon>
     */
    public static function milestones(MemberContract $contract): array
    {
        $start = $contract->start_date;
        $end = $contract->end_date;
        $cycle = (int) ($contract->plan?->settlement_cycle_months ?? 0);
        if (! $start || ! $end) {
            return $end ? [$end->copy()] : [];
        }
        $dates = [];
        if ($cycle > 0) {
            for ($k = 1; $k <= 20; $k++) {
                $d = $start->copy()->addMonthsNoOverflow($cycle * $k);
                if ($d->gte($end)) {
                    break;
                }
                $dates[] = $d;
            }
        }
        $dates[] = $end->copy();   // the last settlement is always the contract's end

        return $dates;
    }

    public function generate(MemberContract $contract, float $amount, ?string $note, ?int $userId, ?Carbon $today = null): ContractSettlement
    {
        $today ??= Carbon::today();
        abort_if($amount <= 0, 422, 'Settlement value must be greater than zero.');

        return DB::transaction(function () use ($contract, $amount, $note, $userId, $today) {
            $contract = MemberContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::isExpired($contract, $today), 422, 'This contract has not expired yet.');
            abort_if($contract->settlements()->exists(), 422, 'A settlement was already generated for this contract.');
            abort_unless($contract->member_id, 422, 'This contract has no distributor to credit.');

            $row = ContractSettlement::create([
                'member_contract_id' => $contract->id,
                'member_id' => $contract->member_id,
                'bond_id' => $contract->bond_id,
                'amount' => round($amount, 2),
                'note' => $note ?: null,
                'paid_on' => $today->toDateString(),
                'created_by' => $userId,
            ]);

            // Straight into the related wallet: cash (spendable / withdrawable) and the
            // gross earning counter, same buckets the commission approval uses.
            $wallet = MemberWallet::firstOrCreate(['member_id' => $contract->member_id]);
            $wallet->increment('cash_balance', $row->amount);
            $wallet->increment('earning_total', $row->amount);

            $contract->update([
                'status' => 'closed',
                'settled_on' => $contract->settled_on ?? $today->toDateString(),
            ]);
            $contract->bond?->update(['status' => 'closed']);

            DB::afterCommit(fn () => Notifier::to(
                $contract->member,
                'settlement',
                'Contract settled — ₹' . Money::group((float) $row->amount) . ' credited',
                'Your contract ' . $contract->contract_no . ' has been settled. ₹' . Money::group((float) $row->amount)
                    . ' was added to your wallet. See My Earnings → Contract Settlement.',
                route: '/business/earnings',
            ));

            return $row;
        });
    }

    /**
     * The board's settlement rule for a contract scheme (2026-11-15) — a redeemable GOLD QR
     * worth  bill × settlement allocation % × settlement QR %:
     *
     *   G5 Retailer     ₹1,00,000 × 50 %  =   50,000 × 72 %  =   36,000
     *   G24 Wholesaler  ₹3,00,000 × 30 %  =   90,000 × 80 %  =   72,000
     *   Sub Dealer      ₹1,00,000 × 75 %  =   75,000 × 80 %  =   60,000
     *   G12 Forward     ₹1,00,000 × 100 % = 1,00,000 × 100 % = 1,00,000
     *
     * The bill is the bond's full invoice value (bonds.epin_value): an old-system contract
     * carries only the bond share in `amount` (₹50,000 on a ₹1 L Retailer bill), a new one the
     * whole bill. A scheme with no allocation % set keeps the earlier basis (allocation_cont
     * share of the contract amount). Null = the scheme has no QR settlement (dealerships).
     *
     * @return array{bill: float, alloc_pct: float, allocation: float, qr_pct: float, worth: float}|null
     */
    public static function rule(MemberContract $contract): ?array
    {
        $plan = $contract->plan;
        if (! $plan || $plan->settlement_qr_pct === null) {
            return null;
        }
        $pct = (float) $plan->settlement_qr_pct;

        if ($plan->settlement_alloc_pct !== null) {
            $bill = (float) ($contract->bond?->epin_value ?: 0) ?: (float) $contract->amount;
            $allocPct = (float) $plan->settlement_alloc_pct;
        } else {
            $bill = (float) $contract->amount;
            $allocPct = (float) $plan->allocation_cont > 0 ? (float) $plan->allocation_cont : 100.0;
        }
        $allocation = round($bill * $allocPct / 100, 2);

        return ['bill' => $bill, 'alloc_pct' => $allocPct, 'allocation' => $allocation, 'qr_pct' => $pct,
            'worth' => round($allocation * $pct / 100, 2)];
    }

    /** Does this contract settle by gold QR (rather than a typed wallet credit)? */
    public static function settlesByQr(MemberContract $contract): bool
    {
        return $contract->bond_id !== null && self::rule($contract) !== null;
    }

    /**
     * Settle a contract with a redeemable gold QR (board 2026-11-15). $stage is the admin's
     * choice — 'interim' (the contract runs on) or 'final' (closes it); null = the suggested one.
     * $amount is what the admin approved — the rule amount unless changed on the form; both are
     * kept. The QR is priced at today's gold rate and redeems only at the contract's own branch
     * (Head Office excepted). Nothing goes to the wallet.
     *
     * $issueQr = false (user 2026-11-19 — "the admin decides whether to issue a QR"): Head Office
     * settled by hand and paid outside the system; the settlement is only RECORDED (mode
     * 'manual') — no QR, no wallet credit.
     */
    public function generateQr(MemberContract $contract, ?float $amount, ?string $note, ?int $userId, ?Carbon $today = null, ?string $stage = null, bool $issueQr = true): ContractSettlement
    {
        $today ??= Carbon::today();

        $row = DB::transaction(function () use ($contract, $amount, $note, $userId, $today, $stage, $issueQr) {
            $contract = MemberContract::with(['plan', 'bond.member', 'member'])->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $rule = self::rule($contract);
            abort_unless($rule && $contract->bond, 422, 'This scheme does not settle by gold QR.');
            $options = self::stageOptions($contract);
            abort_if($options === [], 422, 'The final settlement was already generated for this contract.');
            $stage ??= self::suggestedStage($contract, $today);
            abort_unless(isset($options[$stage]), 422, $stage === 'interim'
                ? 'The interim settlement was already generated for this contract.'
                : 'Choose the interim or the final settlement.');
            $final = $stage === 'final';
            $label = $final ? 'Final settlement' : 'Interim settlement';

            $worth = round($amount ?? $rule['worth'], 2);
            abort_if($worth <= 0, 422, 'Settlement value must be greater than zero.');

            $qr = null;
            if ($issueQr) {
                $rate = (float) (\App\Models\LiveRate::where('country', 'IN')->latest('effective_at')->first()?->gold ?? 0);
                abort_if($rate <= 0, 422, 'No live gold rate set — set it before settling.');
                $grams = app(\App\Services\Charges\ChargeResolver::class)->gramsForWorth($worth, $rate);
                $qr = app(\App\Services\Qr\RedeemableQrService::class)
                    ->mintSettlementQr($contract->bond, $worth, $grams, 'gold', true, 'settlement', self::homeBranchId($contract));
            } else {
                $label .= ' — settled manually, no QR';
            }

            $row = ContractSettlement::create([
                'member_contract_id' => $contract->id,
                'member_id' => $contract->member_id ?: $contract->bond->member_id,
                'bond_id' => $contract->bond_id,
                'amount' => $worth,
                'mode' => $qr ? 'gold_qr' : 'manual',
                'stage' => $stage,
                'redeemable_qr_id' => $qr?->id,
                'rule_amount' => $rule['worth'],
                'note' => mb_substr($label . ($note ? ' · ' . $note : ''), 0, 255),
                'paid_on' => $today->toDateString(),
                'created_by' => $userId,
            ]);

            // Only the FINAL settlement closes the contract; after an interim one it runs on.
            if ($final) {
                $contract->update(['status' => 'closed', 'settled_on' => $today->toDateString()]);
                $contract->bond->update(['status' => 'closed']);
            }

            if (! $qr) {
                DB::afterCommit(fn () => Notifier::to(
                    $contract->member ?: $contract->bond->member,
                    'settlement',
                    ($final ? 'Final settlement' : 'Interim settlement') . ' recorded — ₹' . Money::group($worth),
                    'Your contract ' . $contract->contract_no . ' was settled by Head Office for ₹' . Money::group($worth) . '.',
                    route: '/business/earnings',
                ));

                return $row;
            }

            DB::afterCommit(fn () => Notifier::to(
                $contract->member ?: $contract->bond->member,
                'settlement',
                $label . ' — gold QR worth ₹' . Money::group($worth),
                'Your contract ' . $contract->contract_no . ': ' . lcfirst($label) . ' done. A redeemable gold QR worth ₹' . Money::group($worth)
                    . ' has been issued to you — redeem it at any Lord Jeweller dealer.',
                route: '/qrs/' . $qr->id,
                data: ['qr_code' => (string) $qr->qr_code],
            ));

            return $row;
        });

        // WhatsApp the QR after commit — best effort, never undoes the settlement.
        if (! app()->runningUnitTests() && $row->qr) {
            try {
                app(\App\Services\Qr\RedeemableQrService::class)->deliver($row->qr, false);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $row;
    }
}
