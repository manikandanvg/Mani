<?php

namespace App\Services;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchOrderLine;
use App\Models\CatalogProduct;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\ContractSettlement;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MemberWallet;
use App\Models\RdEntry;
use App\Models\RdSettlement;
use App\Models\RedeemableQr;
use App\Models\RedemptionInvoice;
use App\Models\ResellerCommission;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FORCE DELETE of a member profile (user 2026-11-22). "Cancel invoice → also delete the member
 * profile" refuses as soon as the member has other bills, paid coupons, a redeemed QR … — this
 * is the super admin's way through, with everything on the table first:
 *
 *   statement()  every value issued against the member, over ALL their bills and contracts —
 *                redeemed QR value, Sales Incentive (IC), Promotional Incentive (CBC), dealer
 *                margins, settlements, Gold Saving instalments, wallet — and what happens to each:
 *                  reverse   not paid out yet: simply removed (as "Cancel invoice" does)
 *                  recover   paid into a wallet: taken back from that wallet …
 *                  charge    … and whatever was already WITHDRAWN (or handed over as goods)
 *                            is deducted from the CASH STOCK of the branch that issued the bill,
 *                            which may go below zero (user's ruling)
 *   delete()     does exactly that, cancels every bill / contract of the member and removes the
 *                profile (soft delete, logins closed). The full statement goes to the activity log.
 *
 * Still refused — nothing a force can settle in money: the member has a team (remove the direct
 * distributors first), runs a dealer branch, or a bill carries customized-order pieces / an Area
 * Distributor branch that cannot close. Withdrawal requests Head Office has not paid are
 * cancelled (the money returns to the wallet and is taken back from there); Digi metal and
 * whatever else is left in the member's own wallet is forfeited — all of it listed first.
 * Rows already PAID stay on record as paid (they were, and TDS was withheld on them); the
 * recovery is its own entry — a wallet debit or a cash-stock movement (ref_type force_delete).
 */
class MemberForceDeleteService
{
    public const MOVEMENT_REF = 'force_delete';

    public function __construct(protected SalesCancelService $cancel)
    {
    }

    /** Reasons no force can get past, each a full sentence. Empty = the profile can be force deleted. */
    public function blockers(Member $member): array
    {
        $out = [];
        $code = $member->member_code;

        $directs = Member::where(fn ($q) => $q->where('upline_id', $member->id)->orWhere('referrer_id', $member->id))
            ->where('id', '!=', $member->id)->count();
        if ($directs > 0) {
            $out[] = $code . ' has ' . $directs . ' direct distributor' . ($directs === 1 ? '' : 's') . ' — remove '
                . ($directs === 1 ? 'that distributor' : 'those ' . $directs) . ' first, then try to delete ' . $code . ' again.';
        }

        $invoiceIds = $this->invoices($member)->pluck('id')->all();
        $opened = $invoiceIds ? Branch::whereIn('opened_invoice_id', $invoiceIds)->pluck('id')->all() : [];
        if (User::where('member_code', $code)->whereNotNull('branch_id')->whereNotIn('branch_id', $opened ?: [0])->exists()) {
            $out[] = $code . ' runs a dealer branch — close or re-assign that branch first.';
        }
        foreach ($this->invoices($member) as $invoice) {
            if (BranchOrderLine::where('sales_invoice_id', $invoice->id)->exists()) {
                $out[] = 'Bill ' . $invoice->invoice_no . ' carries customized-order pieces — cancel the order flow first.';
            } elseif ($why = app(AreaDistributorService::class)->cancelBlocker($invoice)) {
                $out[] = 'Bill ' . $invoice->invoice_no . ': ' . $why;
            }
        }

        return $out;
    }

    /** The member's standing (not cancelled) bills, oldest first. */
    protected function invoices(Member $member)
    {
        return SalesInvoice::where('customer_member_id', $member->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->orderBy('date')->orderBy('id')->get();
    }

    /** The member's cash-wallet withdrawals Head Office has not paid yet (pending / requested). */
    protected function unpaidWithdrawals(Member $member)
    {
        return WalletWithdrawal::where('member_id', $member->id)->where('wallet', '!=', 'branch_digi')
            ->whereIn('status', ['pending', 'requested'])->orderBy('id')->get();
    }

    /** The member's standing contracts (bonds), oldest first. */
    protected function bonds(Member $member)
    {
        return Bond::with('plan')->where('member_id', $member->id)->where('status', '!=', 'cancelled')->orderBy('bond_date')->orderBy('id')->get();
    }

    /**
     * Everything issued against the member and what a force delete does with it.
     *
     * @return array{
     *   member: Member, blockers: string[],
     *   bills: array<int, array{invoice_no:string, date:?string, branch:string, amount:float, plan:?string}>,
     *   rows: array<int, array{bill:string, branch_id:?int, branch:string, kind:string, detail:string, amount:float, action:string, recover:float, charge:float, wallet_member_id:?int, wallet_member:?string}>,
     *   totals: array{issued:float, reverse:float, recover:float, charge:float, forfeit:float},
     *   charges: array<int, array{branch_id:int, branch:string, amount:float, cash_stock:float, after:float}>,
     *   wallet_debits: array<int, array{member_id:int, member:string, amount:float}>,
     *   forfeit: array{cash:float, coupon:float, epin:float, gold_g:float, silver_g:float}
     * }
     */
    public function statement(Member $member): array
    {
        $rows = [];
        $bills = [];
        $bonds = $this->bonds($member);
        $invoices = $this->invoices($member)->keyBy('invoice_no');
        $branchNames = [];
        $branchName = function (?int $id) use (&$branchNames) {
            if (! $id) {
                return '—';
            }

            return $branchNames[$id] ??= (string) (Branch::withTrashed()->find($id)?->name ?? 'Branch #' . $id);
        };
        // running cash still in each wallet while the rows are walked — one wallet may owe several rows
        $available = [];
        $walletCash = function (int $memberId) use (&$available): float {
            return $available[$memberId] ??= max(0.0, (float) (MemberWallet::find($memberId)?->cash_balance ?? 0));
        };
        $names = [];
        $memberName = function (?int $id) use (&$names) {
            if (! $id) {
                return null;
            }

            return $names[$id] ??= (($m = Member::withTrashed()->find($id)) ? $m->member_code . ' ' . $m->name : 'Member #' . $id);
        };
        $add = function (string $bill, ?int $branchId, string $kind, string $detail, float $amount, string $action, ?int $walletMemberId = null)
            use (&$rows, &$available, $walletCash, $branchName, $memberName) {
            $amount = round($amount, 2);
            $recover = 0.0;
            $charge = 0.0;
            if ($action === 'recover') {
                // first from the wallet the money went to; what was withdrawn falls on the branch
                if ($walletMemberId) {
                    $recover = round(min($amount, $walletCash($walletMemberId)), 2);
                    $available[$walletMemberId] = round($available[$walletMemberId] - $recover, 2);
                }
                $charge = round($amount - $recover, 2);
            } elseif ($action === 'charge') {
                $charge = $amount;
            }
            $rows[] = [
                'bill' => $bill, 'branch_id' => $branchId, 'branch' => $branchName($branchId), 'kind' => $kind, 'detail' => $detail,
                'amount' => $amount, 'action' => $action, 'recover' => $recover, 'charge' => $charge,
                'wallet_member_id' => $walletMemberId, 'wallet_member' => $memberName($walletMemberId),
            ];
        };

        foreach ($invoices as $invoice) {
            $bills[] = [
                'invoice_no' => $invoice->invoice_no, 'date' => $invoice->date ? Carbon::parse($invoice->date)->format('d M Y') : null,
                'branch' => $branchName((int) $invoice->branch_id), 'amount' => (float) $invoice->grand_total,
                'plan' => $bonds->firstWhere('invoice_no', $invoice->invoice_no)?->plan?->code,
            ];
        }

        // ── the member's own wallet withdrawals still on the way ──
        // not yet paid by Head Office → the request is cancelled, the money is back in the wallet
        // (and so can be taken back below); already paid to the branch → it counts as withdrawn
        $unpaid = $this->unpaidWithdrawals($member);
        if ($unpaid->isNotEmpty()) {
            $back = round((float) $unpaid->sum('amount'), 2);
            $available[$member->id] = round($walletCash($member->id) + $back, 2);
            $add('Wallet', null, 'Wallet withdrawal — not paid yet', $unpaid->count() . ' request' . ($unpaid->count() === 1 ? '' : 's')
                . ' cancelled; the money returns to the wallet first', $back, 'info');
        }
        foreach (WalletWithdrawal::where('member_id', $member->id)->where('wallet', '!=', 'branch_digi')->where('status', 'admin_paid')->get() as $w) {
            $add('Wallet', (int) $w->branch_id ?: null, 'Wallet withdrawal — paid to the branch', 'Head Office already paid ' . $branchName((int) $w->branch_id)
                . ' for the hand-over (' . $w->created_at?->format('d M Y') . ') — counts as withdrawn', (float) $w->amount, 'info');
        }

        // ── contracts: incentive, coupons, settlements, instalments ──
        $bondIds = [];
        foreach ($bonds as $bond) {
            $bondIds[] = $bond->id;
            $invoice = $bond->invoice_no ? $invoices->get($bond->invoice_no) : null;
            $bill = $bond->invoice_no ?: 'Contract #' . $bond->id;
            $branchId = (int) ($invoice?->branch_id ?: $bond->branch_id) ?: null;
            if (! $invoice) {
                $bills[] = ['invoice_no' => $bill . ' (contract, no bill here)', 'date' => $bond->bond_date ? Carbon::parse($bond->bond_date)->format('d M Y') : null,
                    'branch' => $branchName($branchId), 'amount' => (float) ($bond->epin_value ?: $bond->value), 'plan' => $bond->plan?->code];
            }

            // Sales Incentive / salary lines the bill produced for the people above
            foreach (CommissionLedger::where('bond_id', $bond->id)->orderBy('id')->get() as $row) {
                $label = CommissionApprovalService::label($row->type);
                $who = $memberName((int) $row->member_id);
                if ($row->status === 'paid') {
                    $gross = round((float) $row->amount / (float) ($row->fx_rate ?: 1), 2);
                    $net = (float) $row->net_amount > 0 ? (float) $row->net_amount : $this->netOf($gross, (string) $row->type);
                    $add($bill, $branchId, $label . ' — PAID', $who . ' · level ' . (int) $row->level . ' · gross ₹' . Money::group($gross)
                        . ($row->paid_on ? ' · paid ' . $row->paid_on->format('d M Y') : ''), $net, 'recover', (int) $row->member_id);
                } else {
                    $add($bill, $branchId, $label . ' — not paid', $who . ' · level ' . (int) $row->level . ' · ' . $row->status, (float) $row->amount, 'reverse');
                }
            }

            // Promotional Incentive coupons of the contract (the member's own)
            $pending = CbcEntry::where('bond_id', $bond->id)->where('status', 'pending');
            if (($n = (clone $pending)->count()) > 0) {
                $add($bill, $branchId, 'Promotional Incentive (CBC) — not paid', $n . ' coupon' . ($n === 1 ? '' : 's') . ' not yet paid', (float) (clone $pending)->sum('worth'), 'reverse');
            }
            foreach (CbcEntry::where('bond_id', $bond->id)->where('status', '!=', 'pending')->orderBy('cbc_date')->get() as $row) {
                $worth = round((float) $row->worth / (float) ($row->fx_rate ?: 1), 2);
                $cash = (float) $row->cash_amount > 0 ? (float) $row->cash_amount
                    : CommissionApprovalService::cbcSplit($worth, (bool) ($bond->plan?->cbc_dealer_rule ?? false))['cash'];
                $add($bill, $branchId, 'Promotional Incentive (CBC) — PAID', 'coupon ' . ($row->cbc_date?->format('M Y') ?? '') . ' · worth ₹' . Money::group($worth)
                    . ($row->paid_on ? ' · paid ' . $row->paid_on->format('d M Y') : ''), $cash, 'recover', (int) $row->member_id);
            }

            // Contract settlements paid without a QR (a settlement QR is counted with the QRs below)
            foreach (ContractSettlement::where('bond_id', $bond->id)->whereNull('redeemable_qr_id')->whereNotNull('paid_on')->get() as $s) {
                $toWallet = ! in_array((string) $s->mode, ['manual', 'cash', 'hand'], true);
                $add($bill, $branchId, 'Contract settlement — PAID', ucfirst((string) $s->mode) . ' · ' . Carbon::parse($s->paid_on)->format('d M Y'),
                    (float) $s->amount, $toWallet ? 'recover' : 'charge', $toWallet ? $member->id : null);
            }
            foreach (RdSettlement::where('bond_id', $bond->id)->whereNull('redeemable_qr_id')->get() as $s) {
                $add($bill, (int) ($s->branch_id ?: $branchId) ?: null, 'Gold Saving settlement — handed over', ucfirst((string) $s->mode) . ' · ' . ucfirst((string) $s->metal)
                    . ($s->settled_on ? ' · ' . Carbon::parse($s->settled_on)->format('d M Y') : ''), (float) $s->worth, 'charge');
            }

            // Gold Saving instalments are money the member PAID IN — shown, never moved by the system
            $rd = RdEntry::where('bond_id', $bond->id);
            if (($n = (clone $rd)->count()) > 0) {
                $add($bill, $branchId, 'Gold Saving instalments collected', $n . ' instalment' . ($n === 1 ? '' : 's')
                    . ' received from the member — refund to the member is settled by the branch, outside the system', (float) (clone $rd)->sum('value'), 'info');
            }
        }

        // ── stock QRs of the member (billing, Gold Saving, renewal, settlement) ──
        $qrs = RedeemableQr::where(fn ($q) => $q->where('member_id', $member->id)
            ->when($bondIds, fn ($w) => $w->orWhereIn('bond_id', $bondIds))
            ->when($invoices->isNotEmpty(), fn ($w) => $w->orWhereIn('invoice_no', $invoices->keys()->all())))
            ->whereIn('status', ['pending', 'redeemed'])->orderBy('id')->get();
        foreach ($qrs as $qr) {
            $bond = $qr->bond_id ? $bonds->firstWhere('id', $qr->bond_id) : null;
            $invoiceNo = $qr->invoice_no ?: $bond?->invoice_no;
            $invoice = $invoiceNo ? $invoices->get($invoiceNo) : null;
            $bill = $invoiceNo ?: ($bond ? 'Contract #' . $bond->id : 'QR ' . $qr->qr_code);
            $branchId = (int) ($invoice?->branch_id ?: $qr->branch_id ?: $bond?->branch_id) ?: null;
            $kind = ucfirst((string) ($qr->kind ?: 'stock')) . ' QR';
            if ($qr->status === 'redeemed') {
                $redemption = RedemptionInvoice::where('redeemable_qr_id', $qr->id)->orderByDesc('id')->first();
                $value = (float) ($redemption?->grand_total ?: 0);
                if ($value <= 0) {
                    $value = (float) $qr->cash_worth > 0 ? (float) $qr->cash_worth : (float) $qr->gram_worth * (float) $qr->frozen_rate;
                }
                $add($bill, $branchId, $kind . ' — REDEEMED', ($redemption ? 'redemption ' . $redemption->invoice_no : 'QR ' . $qr->qr_code)
                    . ' at ' . $branchName((int) ($qr->redeem_branch_id ?: $redemption?->branch_id))
                    . ($qr->redeemed_at ? ' · ' . Carbon::parse($qr->redeemed_at)->format('d M Y') : '') . ' — the goods were handed over', $value, 'charge');
            } else {
                $value = (float) $qr->cash_worth > 0 ? (float) $qr->cash_worth : (float) $qr->gram_worth * (float) $qr->frozen_rate;
                $add($bill, $branchId, $kind . ' — not redeemed', 'QR ' . $qr->qr_code . ' is cancelled', $value, 'reverse');
            }
        }

        // ── dealer margins paid on the bills ──
        foreach ($invoices as $invoice) {
            foreach (ResellerCommission::where('invoice_no', $invoice->invoice_no)->orderBy('id')->get() as $rc) {
                $label = CommissionApprovalService::label(CommissionApprovalService::resellerTypeKey($rc) ?: 'RESELLER');
                $gross = round((float) $rc->com_value / (float) ($rc->fx_rate ?: 1), 2);
                if ($rc->status === 'paid') {
                    $net = (float) $rc->net_amount > 0 ? (float) $rc->net_amount : $this->netOf($gross, CommissionApprovalService::resellerTypeKey($rc));
                    $payee = $rc->payeeMember();
                    $add($invoice->invoice_no, (int) ($rc->branch_id ?: $invoice->branch_id), $label . ' — PAID', $branchName((int) $rc->branch_id) . ' · gross ₹' . Money::group($gross)
                        . ($rc->paid_on ? ' · paid ' . $rc->paid_on->format('d M Y') : ''), $net, 'recover', $payee?->id);
                } else {
                    $add($invoice->invoice_no, (int) ($rc->branch_id ?: $invoice->branch_id), $label . ' — not paid', $branchName((int) $rc->branch_id) . ' · removed from the branch balance', $gross, 'reverse');
                }
            }
        }

        // ── what is left in the member's own wallet after the recoveries above is forfeited ──
        $wallet = $member->wallet;
        $forfeit = [
            'cash' => round(max(0.0, isset($available[$member->id]) ? $available[$member->id] : (float) ($wallet?->cash_balance ?? 0)), 2),
            'coupon' => round(max(0.0, (float) ($wallet?->coupon_balance ?? 0)), 2),
            'epin' => round(max(0.0, (float) ($wallet?->epin_balance ?? 0)), 2),
            'gold_g' => round(max(0.0, (float) ($wallet?->digi_gold_grams ?? 0)), 4),
            'silver_g' => round(max(0.0, (float) ($wallet?->digi_silver_grams ?? 0)), 4),
        ];

        $charges = [];
        $debits = [];
        foreach ($rows as $r) {
            if ($r['charge'] > 0) {
                $id = (int) $r['branch_id'];
                $charges[$id] ??= ['branch_id' => $id, 'branch' => $r['branch'], 'amount' => 0.0];
                $charges[$id]['amount'] = round($charges[$id]['amount'] + $r['charge'], 2);
            }
            if ($r['recover'] > 0) {
                $id = (int) $r['wallet_member_id'];
                $debits[$id] ??= ['member_id' => $id, 'member' => (string) $r['wallet_member'], 'amount' => 0.0];
                $debits[$id]['amount'] = round($debits[$id]['amount'] + $r['recover'], 2);
            }
        }
        foreach ($charges as $id => &$c) {
            $c['cash_stock'] = $id ? round(BranchOrderService::cashStockBalance($id), 2) : 0.0;
            $c['after'] = round($c['cash_stock'] - $c['amount'], 2);
        }
        unset($c);

        $blockers = $this->blockers($member);
        if (isset($charges[0])) {
            $blockers[] = 'A value of ₹' . Money::group($charges[0]['amount']) . ' has no issuing branch on record — it cannot be charged anywhere.';
        }
        if ($charges && ! CatalogProduct::where('material', 'cash')->exists()) {
            $blockers[] = 'There is no cash-stock item in the catalogue to charge the branch with.';
        }

        $sum = fn (string $action) => round(array_sum(array_map(fn ($r) => $r['action'] === $action ? $r['amount'] : 0.0, $rows)), 2);

        return [
            'member' => $member,
            'blockers' => $blockers,
            'bills' => $bills,
            'rows' => $rows,
            'totals' => [
                'issued' => round($sum('recover') + $sum('charge'), 2),
                'reverse' => $sum('reverse'),
                'recover' => round(array_sum(array_column($rows, 'recover')), 2),
                'charge' => round(array_sum(array_column($rows, 'charge')), 2),
                'forfeit' => round($forfeit['cash'] + $forfeit['coupon'] + $forfeit['epin'], 2),
            ],
            'charges' => array_values($charges),
            'wallet_debits' => array_values($debits),
            'forfeit' => $forfeit,
        ];
    }

    /** Net a gross commission reached the wallet with, when the row itself does not say. */
    protected function netOf(float $gross, ?string $type): float
    {
        [$tds, $svc] = CommissionApprovalService::chargesFor($type, $gross);

        return round($gross - round($gross * $tds / 100, 2) - round($gross * $svc / 100, 2), 2);
    }

    /**
     * Force delete: settle the statement, cancel every bill and contract, remove the profile.
     *
     * @return array the statement that was settled
     */
    public function delete(Member $member, ?int $userId, string $reason): array
    {
        $reason = mb_substr(trim($reason), 0, 255);
        abort_if($reason === '', 422, 'Give the reason for the force delete.');

        return DB::transaction(function () use ($member, $userId, $reason) {
            $member = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_if(($why = $this->blockers($member)) !== [], 422, implode(' ', $why));
            // withdrawal requests Head Office has not paid are withdrawn — the money is back in
            // the wallet before the statement is drawn, so it is taken back from there
            foreach ($this->unpaidWithdrawals($member) as $w) {
                app(\App\Services\Lbox\WalletWithdrawalService::class)->cancel($w, $userId, 'Force delete of ' . $member->member_code);
            }
            $statement = $this->statement($member);
            abort_if($statement['blockers'] !== [], 422, implode(' ', $statement['blockers']));
            $tag = 'Force delete of ' . $member->member_code;

            // 1. take back what is still in the wallets it was paid into
            foreach ($statement['wallet_debits'] as $d) {
                $wallet = MemberWallet::whereKey($d['member_id'])->lockForUpdate()->first();
                $take = round(min($d['amount'], max(0.0, (float) ($wallet?->cash_balance ?? 0))), 2);
                // the wallet moved since the statement was drawn → the difference falls on the branch
                abort_if($take + 0.004 < $d['amount'], 422, 'The wallet of ' . $d['member'] . ' changed while this was being processed — open the statement again.');
                $wallet->decrement('cash_balance', $take);
                if ($d['member_id'] !== $member->id) {
                    \App\Services\Push\Notifier::to(Member::find($d['member_id']), 'commission', 'Incentive reversed — ₹' . Money::group($take),
                        '₹' . Money::group($take) . ' paid to you on the bills of ' . $member->member_code . ' (' . $member->name
                        . ') was taken back from your cash wallet: those bills were cancelled by Head Office.', route: '/wallet');
                }
            }

            // 2. what was withdrawn / handed over is charged to the issuing branch's cash stock
            $perBill = [];
            foreach ($statement['rows'] as $r) {
                if ($r['charge'] > 0) {
                    $key = $r['branch_id'] . '|' . $r['bill'];
                    $perBill[$key] ??= ['branch_id' => (int) $r['branch_id'], 'bill' => $r['bill'], 'amount' => 0.0, 'kinds' => []];
                    $perBill[$key]['amount'] = round($perBill[$key]['amount'] + $r['charge'], 2);
                    $perBill[$key]['kinds'][$r['kind']] = true;
                }
            }
            foreach ($perBill as $c) {
                $this->chargeCashStock($c['branch_id'], $c['amount'], (int) $member->id, $userId,
                    $tag . ' — bill ' . $c['bill'] . ': ' . implode(', ', array_keys($c['kinds'])));
            }

            // 3. every bill is cancelled (stock back, pending lines removed, volumes rolled back) …
            foreach ($this->invoices($member) as $invoice) {
                $this->cancel->cancel($invoice, $userId, $tag . ': ' . $reason, false, ['force' => true, 'silent' => true, 'team_leaves' => false]);
            }
            // … and so is every contract that has no bill in this system
            foreach ($this->bonds($member) as $bond) {
                $this->cancel->cancelBond($bond, $member, null, true);
            }
            RedeemableQr::where('member_id', $member->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            MemberContract::where('member_id', $member->id)->where('status', '!=', 'cancelled')->update(['status' => 'cancelled']);

            // 4. the rest of the member's own wallet is forfeited
            if ($wallet = MemberWallet::whereKey($member->id)->lockForUpdate()->first()) {
                $wallet->update(['cash_balance' => 0, 'coupon_balance' => 0, 'epin_balance' => 0, 'digi_gold_grams' => 0, 'digi_silver_grams' => 0]);
            }

            // 5. the member leaves every ancestor's team count, then the profile goes
            $seen = [$member->id => true];
            $ancestor = $member->upline;
            while ($ancestor && ! isset($seen[$ancestor->id])) {
                $seen[$ancestor->id] = true;
                $ancestor->update(['downline_count' => max(0, (int) $ancestor->downline_count - 1)]);
                $ancestor = $ancestor->upline;
            }
            $this->cancel->removeMember($member);

            \App\Services\Activity\ActivityLogger::log('member', 'force_deleted',
                'Member ' . $member->member_code . ' (' . $member->name . ') FORCE DELETED — ' . count($statement['bills']) . ' bill(s) / contract(s) cancelled; ₹'
                    . Money::group($statement['totals']['recover']) . ' taken back from wallets, ₹' . Money::group($statement['totals']['charge'])
                    . ' charged to branch cash stock. Reason: ' . $reason, [
                    'member' => $member->id,
                    'details' => [
                        'reason' => $reason, 'bills' => $statement['bills'], 'rows' => $statement['rows'], 'totals' => $statement['totals'],
                        'charges' => $statement['charges'], 'wallet_debits' => $statement['wallet_debits'], 'forfeit' => $statement['forfeit'],
                    ],
                ]);

            // each charged dealer is told why the cash stock fell
            foreach ($statement['charges'] as $c) {
                \App\Services\Push\Notifier::to(Branch::find($c['branch_id'])?->distributorUser?->memberAccount, 'stock',
                    'Cash stock charged — ₹' . Money::group($c['amount']),
                    'Head Office deleted the member ' . $member->member_code . ' (' . $member->name . '). ₹' . Money::group($c['amount'])
                    . ' already paid out or redeemed on the bills your branch issued was deducted from your cash stock.', route: '/stock');
            }

            unset($statement['member']);

            return $statement;
        });
    }

    /**
     * Deduct ₹ from a branch's cash stock — it MAY go below zero (user 2026-11-22): the branch
     * owes Head Office what was paid out on the bill it issued.
     */
    protected function chargeCashStock(int $branchId, float $amount, int $memberId, ?int $userId, string $note): void
    {
        if ($amount <= 0) {
            return;
        }
        $stock = Stock::where('branch_id', $branchId)
            ->whereHas('catalogProduct', fn ($q) => $q->where('material', 'cash'))
            ->lockForUpdate()->orderByDesc('quantity')->first();
        if (! $stock) {
            $cp = CatalogProduct::where('material', 'cash')->orderByDesc('is_active')->orderBy('id')->firstOrFail();
            $stock = Stock::firstOrCreate(['branch_id' => $branchId, 'catalog_product_id' => $cp->id, 'order_line_id' => 0], ['quantity' => 0]);
        }
        $stock->decrement('quantity', $amount);
        StockMovement::create([
            'branch_id' => $branchId,
            'catalog_product_id' => $stock->catalog_product_id,
            'type' => 'adjustment',
            'qty_change' => -$amount,
            'balance_after' => $stock->fresh()->quantity,
            'ref_type' => self::MOVEMENT_REF,
            'ref_id' => $memberId,
            'note' => mb_substr($note, 0, 250),
            'moved_on' => Carbon::now()->toDateString(),
            'created_by' => $userId,
        ]);
    }
}
