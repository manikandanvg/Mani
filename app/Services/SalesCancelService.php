<?php

namespace App\Services;

use App\Models\Bond;
use App\Models\BranchOrderLine;
use App\Models\CatalogProduct;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\RedeemableQr;
use App\Models\ResellerCommission;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Board 2026-10-31 (8): a sales invoice is never edited and never deleted — it is CANCELLED,
 * and the cancellation puts everything the bill did back the way it was:
 *
 *   stock            sold pieces return to the branch (+ an 'adjustment' movement, ref sale_cancel)
 *   bond / contract  status → cancelled (the row stays, as the audit trail of the bill)
 *   CBC coupons      unpaid coupons of the bond are removed
 *   Sales Incentive  pending IC lines of the bond are removed
 *   dealer margins   billing / gold / silver margins paid on the bill are removed and the
 *                    branch running balances reduced
 *   network volumes  the buyer's BV and every ancestor's GBV (and team size, for a member
 *                    the bill enrolled) are rolled back and ranks re-evaluated
 *   stock QR         a pending redeemable QR of the bill is cancelled
 *   Area Distributor the branch + login the bill opened are closed, its opening stock taken back
 *
 * Refuses when the bill has gone too far to unwind (QR redeemed, Gold Saving instalments
 * collected, coupons paid, commission approved / paid, customized-order pieces).
 */
class SalesCancelService
{
    public function canCancel(SalesInvoice $invoice): bool
    {
        return $invoice->status !== 'cancelled' && $this->blocker($invoice) === null;
    }

    /**
     * Why the bill cannot be cancelled, or null when it can. $force (user 2026-11-22, the force
     * delete of a member — MemberForceDeleteService) passes everything that can be settled in
     * money: a redeemed QR, collected instalments, paid coupons / incentive / margins.
     */
    public function blocker(SalesInvoice $invoice, bool $force = false): ?string
    {
        if ($invoice->status === 'cancelled') {
            return 'This invoice is already cancelled.';
        }
        if ($force) {
            if (BranchOrderLine::where('sales_invoice_id', $invoice->id)->exists()) {
                return 'Customized-order pieces are billed here; cancel the order flow instead.';
            }

            return app(AreaDistributorService::class)->cancelBlocker($invoice);
        }
        if (RedeemableQr::where('invoice_no', $invoice->invoice_no)->where('status', 'redeemed')->exists()) {
            return 'The stock QR of this bill has already been redeemed.';
        }
        $bond = Bond::where('invoice_no', $invoice->invoice_no)->first();
        if ($bond) {
            if ($bond->rdEntries()->exists()) {
                return 'Gold Saving instalments were already collected against this contract.';
            }
            if (CbcEntry::where('bond_id', $bond->id)->whereIn('status', ['paid', 'used'])->exists()) {
                return 'Promotional (CBC) coupons of this contract were already paid or used.';
            }
            if (CommissionLedger::where('bond_id', $bond->id)->where('status', '!=', 'pending')->exists()) {
                return 'Sales Incentive on this bill was already approved or paid — reverse it under Commission Approval first.';
            }
        }
        if (ResellerCommission::where('invoice_no', $invoice->invoice_no)->where('status', 'paid')->exists()) {
            return 'Dealer margin on this bill was already paid out.';
        }
        if (BranchOrderLine::where('sales_invoice_id', $invoice->id)->exists()) {
            return 'Customized-order pieces are billed here; cancel the order flow instead.';
        }

        // an Area Distributor bill opened a branch + handed over stock (complaint 2026-11-11)
        return app(AreaDistributorService::class)->cancelBlocker($invoice);
    }

    /**
     * Legacy `deleteEntireMemberData` (user 2026-11-11): the admin may tick "also delete the
     * member profile" when a bill enrolled somebody by mistake. Why that is NOT possible for
     * this bill's member, or null when the profile can go with the bill.
     */
    public function memberDeleteBlocker(SalesInvoice $invoice): ?string
    {
        $member = $invoice->customer_member_id ? Member::find($invoice->customer_member_id) : null;
        if (! $member) {
            return 'This bill has no member profile.';
        }
        if (SalesInvoice::where('customer_member_id', $member->id)->where('id', '!=', $invoice->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))->exists()) {
            return $member->member_code . ' has other bills — the profile is still needed.';
        }
        if (Bond::where('member_id', $member->id)->where('invoice_no', '!=', $invoice->invoice_no)->where('status', '!=', 'cancelled')->exists()) {
            return $member->member_code . ' holds other contracts — the profile is still needed.';
        }
        if (Member::where('upline_id', $member->id)->orWhere('referrer_id', $member->id)->exists()) {
            return $member->member_code . ' already has distributors under them (team / referrals).';
        }
        if (\App\Models\EmployeeProfile::withTrashed()->where('member_id', $member->id)->exists()) {
            return $member->member_code . ' is on the payroll (employee record).';
        }
        $wallet = $member->wallet;
        if ($wallet && ((float) $wallet->cash_balance > 0 || (float) $wallet->coupon_balance > 0 || (float) $wallet->epin_balance > 0)) {
            return $member->member_code . ' has a wallet balance.';
        }
        $opened = \App\Models\Branch::where('opened_invoice_id', $invoice->id)->pluck('id')->all();
        if (\App\Models\User::where('member_code', $member->member_code)->whereNotNull('branch_id')->whereNotIn('branch_id', $opened ?: [0])->exists()) {
            return $member->member_code . ' runs a dealer branch.';
        }

        return null;
    }

    /**
     * @param array{force?: bool, silent?: bool, team_leaves?: bool, stock_to?: int} $opt  force = part of a member's
     *        force delete (paid rows stay on record, their money is settled by the caller);
     *        stock_to = the branch the sold stock goes to instead of the issuing branch
     *        (board 2026-11-25: Force Close(Old) routes it to Head Office);
     *        silent = no push to the member; team_leaves = say outright whether the member leaves
     *        the ancestors' team count with this bill (the caller counts it once itself).
     */
    public function cancel(SalesInvoice $invoice, ?int $userId, ?string $reason = null, bool $deleteMember = false, array $opt = []): SalesInvoice
    {
        $force = (bool) ($opt['force'] ?? false);
        $silent = (bool) ($opt['silent'] ?? false);

        return DB::transaction(function () use ($invoice, $userId, $reason, $deleteMember, $force, $silent, $opt) {
            $archive = null;
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->with('lines')->firstOrFail();
            if ($why = $this->blocker($invoice, $force)) {
                abort(422, $why);
            }
            $this->teamLeaves = array_key_exists('team_leaves', $opt) ? (bool) $opt['team_leaves'] : null;
            // the member's data as it stands, before the bill is unwound (user 2026-11-22)
            $archive = $deleteMember && $invoice->customer_member_id && ($leaving = Member::find($invoice->customer_member_id))
                ? app(DeletedMemberArchiver::class)->snapshot($leaving, ['mode' => 'with_invoice', 'reason' => $reason, 'by' => $userId])
                : null;
            if ($deleteMember && ($why = $this->memberDeleteBlocker($invoice))) {
                abort(422, 'Member profile cannot be deleted: ' . $why . ' Untick the option to cancel the bill only.');
            }
            $this->memberLeaves = $deleteMember;

            $member = $invoice->customer_member_id ? Member::find($invoice->customer_member_id) : null;
            $bond = Bond::where('invoice_no', $invoice->invoice_no)->first();
            $fx = (float) ($invoice->fx_rate ?: 1);

            // --- dealer margins (instant, 'passed') → removed + branch balances reduced
            foreach (ResellerCommission::where('invoice_no', $invoice->invoice_no)->get() as $rc) {
                if ($force && $rc->status === 'paid') {
                    continue;   // paid out: stays on record, the money is recovered by the force delete
                }
                $local = round((float) $rc->com_value / $fx, 2);
                $column = match ((int) $rc->com_type_id) {
                    ResellerCommission::COM_BILL_MARGIN => 'bill_margin',
                    ResellerCommission::COM_GOLD_MARGIN => 'gold_gm_margin',
                    ResellerCommission::COM_SILVER_MARGIN => 'silver_gm_margin',
                    default => null,
                };
                if ($column && $rc->branch_id) {
                    DB::table('branches')->where('id', $rc->branch_id)->decrement($column, $local);
                }
                $rc->delete();
            }

            // --- bond, coupons, incentive lines, contract, QR
            if ($bond) {
                $this->cancelBond($bond, $member, $invoice, $force);
            }
            RedeemableQr::where('invoice_no', $invoice->invoice_no)->where('status', 'pending')->update(['status' => 'cancelled']);

            // --- stock back to the branch (and out of the Area Distributor branch the bill
            //     stocked; a branch + login this bill opened are closed)
            app(AreaDistributorService::class)->reverse($invoice, $userId);
            $this->restoreStock($invoice, $userId, isset($opt['stock_to']) ? (int) $opt['stock_to'] : null);

            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason ? mb_substr($reason, 0, 255) : null,
            ]);
            \App\Services\Activity\ActivityLogger::log('sales', 'cancelled', 'Invoice ' . $invoice->invoice_no . ' CANCELLED' . ($reason ? ' — ' . $reason : ''), [
                'subject' => $invoice, 'details' => ['reason' => $reason, 'bond_id' => $bond?->id, 'grand_total' => (float) $invoice->grand_total],
            ]);

            $this->teamLeaves = null;

            if ($member && $deleteMember) {
                $this->removeMember($member, $archive);
                \App\Services\Activity\ActivityLogger::log('member', 'deleted_with_invoice',
                    'Member ' . $member->member_code . ' (' . $member->name . ') deleted together with cancelled invoice ' . $invoice->invoice_no, [
                        'subject' => $invoice, 'member' => $member->id, 'details' => ['reason' => $reason],
                    ]);
            } elseif ($member && ! $silent) {
                \App\Services\Push\Notifier::to(
                    $member, 'invoice', 'Invoice ' . $invoice->invoice_no . ' cancelled',
                    'Your bill dated ' . Carbon::parse($invoice->date)->format('d M Y') . ' for ₹' . \App\Support\Money::group((float) $invoice->grand_total)
                        . ' was cancelled by ' . ($invoice->branch?->name ?? 'the branch') . ($reason ? ' — ' . $reason : '.')
                        . ' Any contract, coupons or stock QR issued on it are void.',
                    route: '/invoices',
                );
            }

            return $invoice->fresh();
        });
    }

    /**
     * The contract side of a cancel: unpaid coupons and incentive lines removed, contract and
     * pending QR cancelled, network volumes rolled back, bond → cancelled. Also used on its own
     * for a contract that has no bill in this system (force delete). With $force the lines that
     * were approved but not yet credited go too; PAID rows always stay on record.
     */
    public function cancelBond(Bond $bond, ?Member $member, ?SalesInvoice $invoice = null, bool $force = false): void
    {
        if ($bond->status === 'cancelled') {
            return;
        }
        CbcEntry::where('bond_id', $bond->id)->where('status', 'pending')->delete();
        CommissionLedger::where('bond_id', $bond->id)
            ->when($force, fn ($q) => $q->where('status', '!=', 'paid'), fn ($q) => $q->where('status', 'pending'))->delete();
        MemberContract::where('bond_id', $bond->id)->update(['status' => 'cancelled']);
        RedeemableQr::where('bond_id', $bond->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        if ($invoice === null) {
            $this->teamLeaves = false;   // a bare contract never moves the team count
        }
        $this->rollBackNetwork($bond, $member, $invoice);
        if ($invoice === null) {
            $this->teamLeaves = null;
        }
        $bond->update(['status' => 'cancelled']);
    }

    /**
     * Remove a member profile (user 2026-11-22: nothing of a deleted member stays in the live
     * tables). The member's data was copied to the deleted-member store by
     * DeletedMemberArchiver::snapshot(); here the live rows go. The code is never issued again.
     */
    public function removeMember(Member $member, ?\App\Models\DeletedMember $archive = null): void
    {
        $archiver = app(DeletedMemberArchiver::class);
        $archiver->purge($archive ?? $archiver->snapshot($member), $member);
    }

    /** True while cancel() is also deleting the member — they leave every ancestor's team count. */
    protected bool $memberLeaves = false;

    /** Set by a caller that decides the team count itself (force delete); null = work it out. */
    protected ?bool $teamLeaves = null;

    /** Undo SalesService::applyNetworkDeltas for this bond (BV, GBV, unpure, team size) and re-rank. */
    protected function rollBackNetwork(Bond $bond, ?Member $member, ?SalesInvoice $invoice): void
    {
        if (! $member) {
            return;
        }
        // BV/GBV were credited with the FULL bill (legacy way, user 2026-11-04), the bond keeps
        // only the allocation share — scale back up before taking it off again.
        $value = $bond->plan ? $bond->plan->billValueFromBond((float) $bond->value, (float) $bond->epin_value) : (float) ($bond->epin_value ?: $bond->value);
        $unpure = $bond->plan ? $bond->plan->unpureFromBondValue((float) $bond->value, (float) $bond->epin_value) : 0.0;

        // A member the bill enrolled (their very first invoice, created with it) also leaves
        // every ancestor's team count.
        $enrolledHere = $this->teamLeaves ?? ($this->memberLeaves || ($invoice !== null
            && SalesInvoice::where('customer_member_id', $member->id)->where('id', '!=', $invoice->id)->doesntExist()
            && $member->created_at && $invoice->created_at
            && abs($member->created_at->diffInSeconds($invoice->created_at)) <= 120));

        $member->update([
            'bv' => max(0, (float) $member->bv - $value),
            'unpure_bv' => max(0, (float) $member->unpure_bv - $unpure),
        ]);

        $chain = [];
        $seen = [$member->id => true];
        $ancestor = $member->upline;
        while ($ancestor && ! isset($seen[$ancestor->id])) {
            $seen[$ancestor->id] = true;
            $ancestor->update([
                'gbv' => max(0, (float) $ancestor->gbv - $value),
                'unpure_gbv' => max(0, (float) $ancestor->unpure_gbv - $unpure),
                'downline_count' => max(0, (int) $ancestor->downline_count - ($enrolledHere ? 1 : 0)),
            ]);
            $chain[] = $ancestor->id;
            $ancestor = $ancestor->upline;
        }

        $network = app(NetworkService::class);
        $network->trigger = 'cancel';   // rank_changes rows from this rollback are tagged as such
        $network->evaluateRank($member->id);
        foreach ($chain as $id) {
            $network->evaluateRank($id);
        }
        $network->trigger = 'billing';
    }

    /**
     * What the bill took out of the branch stock: catalog_product_id => pieces (cash: rupees).
     * Bills written after 2026-11 carry their stock movements with ref_id = invoice id, so the
     * exact quantity is known; older bills fall back to the sale lines (weight → pieces via the
     * catalog unit weight).
     *
     * @return array<int, float>
     */
    public function soldPieces(SalesInvoice $invoice): array
    {
        $out = [];
        $movements = StockMovement::where('ref_type', 'sale')->where('ref_id', $invoice->id)->get();
        if ($movements->isNotEmpty()) {
            foreach ($movements as $m) {
                $out[$m->catalog_product_id] = ($out[$m->catalog_product_id] ?? 0) + abs((float) $m->qty_change);
            }
        } else {
            foreach ($invoice->lines as $line) {
                if (! $line->catalog_product_id) {
                    continue;
                }
                $cp = CatalogProduct::withTrashed()->find($line->catalog_product_id);
                $pieces = $cp ? $cp->piecesFromWeight((float) $line->qty) : (float) $line->qty;
                $out[$line->catalog_product_id] = ($out[$line->catalog_product_id] ?? 0) + $pieces;
            }
        }

        return array_filter($out, fn ($pieces) => $pieces > 0);
    }

    /**
     * Put the sold pieces back — to the issuing branch ("New Close": the bill was a mistake), or
     * to $toBranch (board 2026-11-25, "Force Close(Old)": the stock is NOT returned to the branch,
     * it goes to Head Office, which may hand it out again through Branch Sales). The second kind
     * is also written to force_close_stock, the register behind the Force Close stock report.
     */
    protected function restoreStock(SalesInvoice $invoice, ?int $userId, ?int $toBranch = null): void
    {
        $issuer = (int) $invoice->branch_id;
        $branchId = $toBranch ?: $issuer;
        if (! $branchId) {
            return;
        }
        $member = $toBranch && $invoice->customer_member_id ? Member::find($invoice->customer_member_id) : null;

        foreach ($this->soldPieces($invoice) as $productId => $pieces) {
            $stock = Stock::firstOrCreate(
                ['branch_id' => $branchId, 'catalog_product_id' => $productId, 'order_line_id' => 0],
                ['quantity' => 0],
            );
            $stock->increment('quantity', $pieces);
            $movement = StockMovement::create([
                'branch_id' => $branchId,
                'catalog_product_id' => $productId,
                'type' => 'adjustment',
                'qty_change' => $pieces,
                'balance_after' => $stock->fresh()->quantity,
                'ref_type' => $toBranch ? \App\Models\ForceCloseStock::MOVEMENT_REF : 'sale_cancel',
                'ref_id' => $invoice->id,
                'note' => $toBranch
                    ? mb_substr('Force Close — stock of bill ' . $invoice->invoice_no . ' (branch #' . $issuer . ') taken to Head Office', 0, 250)
                    : 'Invoice ' . $invoice->invoice_no . ' cancelled',
                'moved_on' => Carbon::now()->toDateString(),
                'created_by' => $userId,
            ]);
            if ($toBranch) {
                \App\Models\ForceCloseStock::record('bill_stock', $issuer ?: null, $branchId, (int) $productId, (float) $pieces,
                    $invoice->invoice_no, $member, $movement->id, $userId, 'Stock sold on the bill');
            }
        }
    }
}
