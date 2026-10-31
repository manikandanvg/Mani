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

    /** Why the bill cannot be cancelled, or null when it can. */
    public function blocker(SalesInvoice $invoice): ?string
    {
        if ($invoice->status === 'cancelled') {
            return 'This invoice is already cancelled.';
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

        return null;
    }

    public function cancel(SalesInvoice $invoice, ?int $userId, ?string $reason = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId, $reason) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->with('lines')->firstOrFail();
            if ($why = $this->blocker($invoice)) {
                abort(422, $why);
            }

            $member = $invoice->customer_member_id ? Member::find($invoice->customer_member_id) : null;
            $bond = Bond::where('invoice_no', $invoice->invoice_no)->first();
            $fx = (float) ($invoice->fx_rate ?: 1);

            // --- dealer margins (instant, 'passed') → removed + branch balances reduced
            foreach (ResellerCommission::where('invoice_no', $invoice->invoice_no)->get() as $rc) {
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
                CbcEntry::where('bond_id', $bond->id)->where('status', 'pending')->delete();
                CommissionLedger::where('bond_id', $bond->id)->where('status', 'pending')->delete();
                MemberContract::where('bond_id', $bond->id)->update(['status' => 'cancelled']);
                RedeemableQr::where('bond_id', $bond->id)->where('status', 'pending')->update(['status' => 'cancelled']);
                $this->rollBackNetwork($bond, $member, $invoice);
                $bond->update(['status' => 'cancelled']);
            }
            RedeemableQr::where('invoice_no', $invoice->invoice_no)->where('status', 'pending')->update(['status' => 'cancelled']);

            // --- stock back to the branch
            $this->restoreStock($invoice, $userId);

            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason ? mb_substr($reason, 0, 255) : null,
            ]);

            if ($member) {
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

    /** Undo SalesService::applyNetworkDeltas for this bond (BV, GBV, unpure, team size) and re-rank. */
    protected function rollBackNetwork(Bond $bond, ?Member $member, SalesInvoice $invoice): void
    {
        if (! $member) {
            return;
        }
        $value = (float) $bond->value;
        $unpure = $bond->plan ? $bond->plan->unpureFromBondValue($value) : 0.0;

        // A member the bill enrolled (their very first invoice, created with it) also leaves
        // every ancestor's team count.
        $enrolledHere = SalesInvoice::where('customer_member_id', $member->id)->where('id', '!=', $invoice->id)->doesntExist()
            && $member->created_at && $invoice->created_at
            && abs($member->created_at->diffInSeconds($invoice->created_at)) <= 120;

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
        $network->evaluateRank($member->id);
        foreach ($chain as $id) {
            $network->evaluateRank($id);
        }
    }

    /**
     * Put the sold pieces back. Bills written after this change carry their stock movements
     * with ref_id = invoice id, so the exact pieces come back; older bills fall back to the
     * sale lines (weight → pieces via the catalog unit weight).
     */
    protected function restoreStock(SalesInvoice $invoice, ?int $userId): void
    {
        $branchId = (int) $invoice->branch_id;
        if (! $branchId) {
            return;
        }
        $movements = StockMovement::where('ref_type', 'sale')->where('ref_id', $invoice->id)->get();

        $restore = [];   // catalog_product_id => pieces
        if ($movements->isNotEmpty()) {
            foreach ($movements as $m) {
                $restore[$m->catalog_product_id] = ($restore[$m->catalog_product_id] ?? 0) + abs((float) $m->qty_change);
            }
        } else {
            foreach ($invoice->lines as $line) {
                if (! $line->catalog_product_id) {
                    continue;
                }
                $cp = CatalogProduct::withTrashed()->find($line->catalog_product_id);
                $pieces = $cp ? $cp->piecesFromWeight((float) $line->qty) : (float) $line->qty;
                $restore[$line->catalog_product_id] = ($restore[$line->catalog_product_id] ?? 0) + $pieces;
            }
        }

        foreach ($restore as $productId => $pieces) {
            if ($pieces <= 0) {
                continue;
            }
            $stock = Stock::firstOrCreate(
                ['branch_id' => $branchId, 'catalog_product_id' => $productId, 'order_line_id' => 0],
                ['quantity' => 0],
            );
            $stock->increment('quantity', $pieces);
            StockMovement::create([
                'branch_id' => $branchId,
                'catalog_product_id' => $productId,
                'type' => 'adjustment',
                'qty_change' => $pieces,
                'balance_after' => $stock->fresh()->quantity,
                'ref_type' => 'sale_cancel',
                'ref_id' => $invoice->id,
                'note' => 'Invoice ' . $invoice->invoice_no . ' cancelled',
                'moved_on' => Carbon::now()->toDateString(),
                'created_by' => $userId,
            ]);
        }
    }
}
