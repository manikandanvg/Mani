<?php

namespace App\Services;

use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\MemberWallet;
use App\Models\ResellerCommission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Unified commission approval — the single gate that releases earnings to a member's
 * wallet. The 7 streams live in three tables (IC/GAP → commission_ledger, CBC →
 * cbc_entries, the 4 distributor margins → reseller_commissions); this service maps a
 * UI "type" to the right source query and credits the correct wallet on approval.
 *
 * Nothing credits a wallet before this runs: records are created 'pending'/'passed';
 * approval marks them paid (+ paid date) and posts the amount to the member wallet.
 */
class CommissionApprovalService
{
    /** Cash-back coupon split: 40% to EPIN, the rest to coupon (legacy CBC_TO_EPIN_PCT). */
    public const CBC_TO_EPIN_PCT = 40.0;

    /** Withheld on cash income (IC/GAP + the 4 margins) at approval. CBC coupons are exempt. */
    public const TDS_PCT = 5.0;

    public const SERVICE_PCT = 5.0;

    /**
     * UI dropdown — the 7 commission types in display order.
     * Display names follow the 2026-07 board/auditor terminology: IC is shown as
     * "Promotional Incentive" (legacy Sales Profit) and GAP as "Turnover-based
     * Salary" (legacy Level Income). Internal keys are unchanged.
     */
    public const TYPES = [
        'IC' => 'Promotional Incentive (IC)',
        'GAP' => 'Turnover-based Salary (GAP)',
        'CBC' => 'Cash Back Coupon (CBC)',
        'BILL_MARGIN' => 'Billing Margin',
        'GOLD_MARGIN' => 'Gold Margin',
        'SILVER_MARGIN' => 'Silver Margin',
        'STOCK_TRANSFER_MARGIN' => 'Stock Transfer Margin',
    ];

    /** reseller_commissions.com_type_id for each margin type. */
    protected const RESELLER_COM = [
        'BILL_MARGIN' => 1,
        'GOLD_MARGIN' => 2,
        'SILVER_MARGIN' => 3,
        'STOCK_TRANSFER_MARGIN' => 4,
    ];

    /**
     * Un-paid records of one type within a date range, ordered oldest first.
     * Date is matched against each table's "earned" column.
     */
    public function query(string $type, ?string $from = null, ?string $to = null): Builder
    {
        if (in_array($type, ['IC', 'GAP'], true)) {
            return CommissionLedger::query()
                ->with(['member', 'fromMember'])
                ->where('type', $type)
                ->where('status', '!=', 'paid')
                ->when($from, fn ($q) => $q->whereDate('earned_on', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('earned_on', '<=', $to))
                ->orderBy('earned_on');
        }

        if ($type === 'CBC') {
            return CbcEntry::query()
                ->with(['member'])
                ->where('status', 'pending')
                ->when($from, fn ($q) => $q->whereDate('cbc_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('cbc_date', '<=', $to))
                ->orderBy('cbc_date');
        }

        $com = self::RESELLER_COM[$type] ?? 1;

        return ResellerCommission::query()
            ->with(['branch', 'referenceMember'])
            ->where('com_type_id', $com)
            ->where('status', '!=', 'paid')
            ->when($from, fn ($q) => $q->whereDate('bill_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('bill_date', '<=', $to))
            ->orderBy('bill_date');
    }

    /**
     * Approve one record: mark paid (+ paid date) and credit the beneficiary wallet.
     * Idempotent — returns false if the row was already paid. Wrapped in a transaction.
     */
    public function approve(Model $row, ?string $paidOn = null): bool
    {
        $paidOn ??= Carbon::now()->toDateString();

        return match (true) {
            $row instanceof CommissionLedger => $this->approveLedger($row, $paidOn),
            $row instanceof CbcEntry => $this->approveCbc($row, $paidOn),
            $row instanceof ResellerCommission => $this->approveReseller($row, $paidOn),
            default => false,
        };
    }

    /** IC / GAP → member's cash wallet, net of TDS + service charge. */
    protected function approveLedger(CommissionLedger $row, string $paidOn): bool
    {
        if ($row->status === 'paid') {
            return false;
        }

        DB::transaction(function () use ($row, $paidOn) {
            // amount is INR base; pay in the earner's currency at the rate frozen on the row.
            $gross = round((float) $row->amount / (float) ($row->fx_rate ?: 1), 2);
            ['tds' => $tds, 'service' => $svc, 'net' => $net] = $this->deduct($gross);
            // earning_total tracks gross earned; cash_balance gets the net payout.
            $this->creditWallet($row->member_id, cash: $net, earning: $gross, currency: $row->currency_code ?: 'INR');
            $row->update([
                'status' => 'paid', 'paid_on' => $paidOn,
                'tds' => $tds, 'service_charge' => $svc, 'net_amount' => $net,
            ]);
        });

        return true;
    }

    /** CBC → 40% EPIN + 60% coupon of worth. */
    protected function approveCbc(CbcEntry $row, string $paidOn): bool
    {
        if ($row->status !== 'pending') {
            return false;
        }

        DB::transaction(function () use ($row, $paidOn) {
            // worth is INR base; pay in the currency + rate FROZEN at issue on the row
            // (rows from before the stamping default to INR/1.0 — unchanged behaviour).
            $fx = (float) ($row->fx_rate ?: 1);
            $ccy = $row->currency_code ?: 'INR';
            $worth = round((float) $row->worth / $fx, 2);
            $epin = round($worth * self::CBC_TO_EPIN_PCT / 100, 2);
            $coupon = round($worth - $epin, 2);
            $this->creditWallet($row->member_id, epin: $epin, coupon: $coupon, earning: $worth, currency: $ccy);
            $row->update(['status' => 'paid', 'paid_on' => $paidOn]);
        });

        return true;
    }

    /** Distributor margins → the branch's distributor member wallet (cash). */
    protected function approveReseller(ResellerCommission $row, string $paidOn): bool
    {
        if ($row->status === 'paid') {
            return false;
        }

        DB::transaction(function () use ($row, $paidOn) {
            // com_value is INR base; pay in the earner's currency at the frozen rate.
            $gross = round((float) $row->com_value / (float) ($row->fx_rate ?: 1), 2);
            ['tds' => $tds, 'service' => $svc, 'net' => $net] = $this->deduct($gross);
            $memberId = $this->distributorMemberId($row);
            if ($memberId) {
                $this->creditWallet($memberId, cash: $net, earning: $gross, currency: $row->currency_code ?: 'INR');
            }
            // No mapped distributor member → the branch balance already holds it; just mark paid.
            $row->update([
                'status' => 'paid', 'paid_on' => $paidOn,
                'tds' => $tds, 'service_charge' => $svc, 'net_amount' => $net,
            ]);
        });

        return true;
    }

    /** TDS + service charge withheld from a gross cash commission; returns the breakdown. */
    protected function deduct(float $gross): array
    {
        $tds = round($gross * self::TDS_PCT / 100, 2);
        $svc = round($gross * self::SERVICE_PCT / 100, 2);

        return ['tds' => $tds, 'service' => $svc, 'net' => round($gross - $tds - $svc, 2)];
    }

    /** Resolve a reseller margin's payee: branch → distributor login → mapped member. */
    protected function distributorMemberId(ResellerCommission $row): ?int
    {
        $branch = $row->branch;

        return $branch?->distributorUser?->memberAccount?->id;
    }

    /** Post to the single member wallet (separate balance buckets), creating it if absent. */
    protected function creditWallet(int $memberId, float $cash = 0, float $epin = 0, float $coupon = 0, float $earning = 0, string $currency = 'INR'): void
    {
        $wallet = MemberWallet::firstOrCreate(['member_id' => $memberId]);
        // Pin the wallet to the member's operating currency (all buckets are in it).
        if ($wallet->currency_code !== $currency) {
            $wallet->update(['currency_code' => $currency]);
        }
        if ($cash) {
            $wallet->increment('cash_balance', $cash);
        }
        if ($epin) {
            $wallet->increment('epin_balance', $epin);
        }
        if ($coupon) {
            $wallet->increment('coupon_balance', $coupon);
        }
        if ($earning) {
            $wallet->increment('earning_total', $earning);
        }
    }
}
