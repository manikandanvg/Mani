<?php

namespace App\Services;

use App\Models\MemberContract;
use App\Models\RdEntry;
use App\Models\RedeemableQr;
use App\Models\User;
use App\Services\Qr\RedeemableQrService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Contract settlement engine (board settlement sheet 2026-07-28). One-shot per
 * contract: when start_date + plan.settlement_cycle_months is reached, the plan's
 * settlement config decides what happens —
 *
 *   settlement_qr_pct       mint a gold QR worth pct% of the contract amount
 *   settlement_bonus_months G11 RD: QR worth = paid total + bonus months × monthly amount
 *   settlement_close        close the contract + bond
 *   settlement_suspend      suspend the member's dealer login (branch user)
 *   none of the above       mark the contract 'matured' — dealership withdraw/renewal
 *                           (with the opening-stock check) is a manual decision
 *
 * Settlement QRs are deliberately independent of plan.is_redeem: the sale-time QR is
 * a billing artifact, the settlement QR is the maturity benefit (e.g. PLUS3 sends a
 * closing gold QR even though the plan mints no QR at sale).
 */
class SettlementService
{
    /** QRs minted this run, delivered after the transaction commits. */
    protected array $pendingQrs = [];

    public function __construct(protected RedeemableQrService $qrService) {}

    /** Settle every due contract; returns the number settled. */
    public function run(?Carbon $today = null): int
    {
        $today ??= Carbon::now();
        $count = 0;

        MemberContract::query()
            ->with(['plan', 'bond.member', 'member'])
            ->where('status', 'active')
            ->whereNull('settled_on')
            ->whereHas('plan', fn ($q) => $q->whereNotNull('settlement_cycle_months'))
            ->orderBy('id')
            ->chunkById(100, function ($contracts) use ($today, &$count) {
                foreach ($contracts as $contract) {
                    $due = $contract->start_date?->copy()
                        ->addMonthsNoOverflow((int) $contract->plan->settlement_cycle_months);
                    if ($due && $due->lte($today)) {
                        $this->settle($contract, $today);
                        $count++;
                    }
                }
            });

        $this->deliverPendingQrs();

        return $count;
    }

    /** Apply the plan's settlement to one contract (idempotent via settled_on). */
    public function settle(MemberContract $contract, Carbon $today): void
    {
        DB::transaction(function () use ($contract, $today) {
            $contract = MemberContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($contract->status !== 'active' || $contract->settled_on !== null) {
                return;
            }
            $plan = $contract->plan;
            $bond = $contract->bond;

            $worth = $this->settlementWorth($contract);
            if ($worth > 0 && $bond) {
                $this->pendingQrs[] = $this->mintQr($bond, $worth)->id;
            }

            if ($plan->settlement_suspend) {
                $this->suspendDealerLogin($contract);
            }

            if ($plan->settlement_close) {
                $bond?->update(['status' => 'closed']);
                $status = 'closed';
            } else {
                // No auto-close: QR plans keep running to validity; pure-maturity plans
                // (dealerships, G36) park as 'matured' for the manual withdraw/renewal call.
                $status = $worth > 0 ? 'active' : 'matured';
            }

            $contract->update(['status' => $status, 'settled_on' => $today->toDateString()]);
        });
    }

    /**
     * Settlement QR worth: RD plans sum what was actually paid (billing month + rd_entries)
     * plus the bonus months at the monthly amount; percentage plans take pct% of the
     * contract amount. 0 = no QR.
     */
    protected function settlementWorth(MemberContract $contract): float
    {
        $plan = $contract->plan;
        $bond = $contract->bond;

        if ((int) $plan->settlement_bonus_months > 0 && $bond) {
            $monthly = (float) $bond->value;
            $paid = $monthly + (float) RdEntry::where('bond_id', $bond->id)->sum('value');

            return round($paid + (int) $plan->settlement_bonus_months * $monthly, 2);
        }

        if ($plan->settlement_qr_pct !== null) {
            // "Contract amount" for settlement = the allocation_cont share of the billed
            // value (item 9); plans without a split settle on the full amount.
            $base = (float) $contract->amount;
            if ((float) $plan->allocation_cont > 0) {
                $base = round($base * (float) $plan->allocation_cont / 100, 2);
            }

            return round($base * (float) $plan->settlement_qr_pct / 100, 2);
        }

        return 0.0;
    }

    /** A settlement QR is a fresh QR row — never reuses the sale-time QR. */
    protected function mintQr($bond, float $worth): RedeemableQr
    {
        return $this->qrService->mintSettlementQr($bond, $worth);
    }

    /** "Rid the user access": block the member's dealer branch login (users.status enum). */
    protected function suspendDealerLogin(MemberContract $contract): void
    {
        $memberCode = $contract->member?->member_code ?? $contract->bond?->member?->member_code;
        if (! $memberCode) {
            return;
        }
        User::where('member_code', $memberCode)
            ->whereNotNull('branch_id')
            ->update(['status' => 'blocked']);
    }

    /** WhatsApp the settlement QRs after commit — best-effort, skipped under tests. */
    protected function deliverPendingQrs(): void
    {
        $ids = $this->pendingQrs;
        $this->pendingQrs = [];

        if (! $ids || app()->runningUnitTests()) {
            return;
        }

        foreach ($ids as $id) {
            try {
                if ($qr = RedeemableQr::find($id)) {
                    $this->qrService->deliver($qr, withContract: false);
                    // Settlement acknowledgement (board 2026-08-11: push + inbox).
                    \App\Services\Push\Notifier::to($qr->member, 'settlement',
                        'Scheme settled — your gold QR is ready',
                        'Your contract ' . $qr->invoice_no . ' matured and settled. A gold QR worth ₹'
                            . \App\Support\Money::group((float) $qr->cash_worth) . ' has been issued to you.',
                        route: '/qrs/' . $qr->id,
                        data: ['qr_code' => (string) $qr->qr_code],
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Settlement QR delivery failed: ' . $e->getMessage());
            }
        }
    }
}
