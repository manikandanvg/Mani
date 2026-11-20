<?php

namespace App\Services\Redeem;

use App\Models\Branch;
use App\Models\RedemptionInvoice;
use App\Models\ResellerCommission;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Undo a redemption made by mistake (user 2026-11-17: "the dealer read the QR and submitted,
 * but more stock had to be added — how to undo before the stock order is raised?").
 *
 * Cancelling puts everything back as it was before the QR was read:
 *
 *   stock     the pieces given out return to the redeeming branch
 *   margin    the gold / silver margin of the redemption (not yet paid) is taken back
 *   QR        becomes PENDING again — it can be read and redeemed afresh with the right items
 *   invoice   stays on record as CANCELLED (its number is never used again), out of every
 *             register, GST report and the distributor's app
 *
 * Only while nothing was built on top of it: no restock order raised (or the order was
 * rejected / cancelled), no dealer account opened by it, no margin of it already paid.
 */
class RedemptionCancelService
{
    /** Why this redemption cannot be cancelled — null when it can. */
    public function blocker(RedemptionInvoice $invoice): ?string
    {
        if ($invoice->trashed()) {
            return 'this redemption is already cancelled.';
        }
        // an old-system redemption is final: cancelling it would make its QR redeemable again
        if ($invoice->legacy_restock !== null || ! $invoice->redeemable_qr_id || ! $invoice->qr) {
            return 'it was carried over from the old system (no QR on record here) — nothing to undo in this system.';
        }
        $order = $invoice->restockOrder;
        if ($order && ! in_array($order->status, ['rejected', 'cancelled'], true)) {
            return 'restock order ' . $order->request_no . ' is already raised for it (' . $order->status . ') — cancel that order first.';
        }
        if ($invoice->dealer_created) {
            return 'a dealer account was opened by this redemption.';
        }
        if ($this->margins($invoice)->where('status', '!=', 'passed')->exists()) {
            return 'its gold / silver margin is already approved or paid.';
        }

        return null;
    }

    /**
     * $reopenQr = false: the redemption is removed as a DUPLICATE (its QR had been redeemed
     * before — user 2026-11-20): everything is put back the same way, but the QR is CANCELLED
     * instead of live again, and the member is not told that it can be redeemed.
     */
    public function cancel(RedemptionInvoice $invoice, ?int $userId, ?string $reason, bool $reopenQr = true): void
    {
        DB::transaction(function () use ($invoice, $userId, $reason, $reopenQr) {
            $invoice = RedemptionInvoice::lockForUpdate()->findOrFail($invoice->id);
            abort_if(($why = $this->blocker($invoice)) !== null, 422, 'Cannot cancel: ' . $why);

            // --- stock back to the redeeming branch (exactly what this redemption moved out) ---
            $moved = StockMovement::where('ref_type', 'redemption')->where('ref_id', $invoice->id)->get();
            foreach ($moved->groupBy('catalog_product_id') as $productId => $rows) {
                $pieces = -1 * (float) $rows->sum('qty_change');
                if ($pieces <= 0) {
                    continue;
                }
                $stock = Stock::where('branch_id', $invoice->branch_id)->where('catalog_product_id', $productId)
                    ->lockForUpdate()->first()
                    ?? Stock::create(['branch_id' => $invoice->branch_id, 'catalog_product_id' => $productId, 'quantity' => 0]);
                $stock->increment('quantity', $pieces);
                StockMovement::create([
                    'branch_id' => $invoice->branch_id,
                    'catalog_product_id' => $productId,
                    'type' => 'adjustment',
                    'qty_change' => $pieces,
                    'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'redemption_cancel',
                    'ref_id' => $invoice->id,
                    'note' => 'Redemption ' . $invoice->invoice_no . ' cancelled',
                    'moved_on' => Carbon::now()->toDateString(),
                    'created_by' => $userId,
                ]);
            }

            // --- gold / silver margin of the redemption (still unpaid) taken back ---
            foreach ($this->margins($invoice)->get() as $row) {
                $column = (int) $row->com_type_id === ResellerCommission::COM_GOLD_MARGIN ? 'gold_gm_margin' : 'silver_gm_margin';
                $local = round((float) $row->com_value / (float) ($row->fx_rate ?: 1), 2);
                $branch = Branch::lockForUpdate()->find($row->branch_id);
                $branch?->update([$column => max(0, round((float) $branch->{$column} - $local, 2))]);
                $row->delete();
            }

            // --- the QR is live again ---
            $invoice->qr->update(['status' => $reopenQr ? 'pending' : 'cancelled', 'redeem_branch_id' => null, 'redeemed_at' => null, 'otp' => null, 'otp_sent_at' => null]);

            // --- the invoice stays on record, cancelled ---
            $invoice->forceFill(['cancelled_by' => $userId, 'cancel_reason' => $reason])->save();
            $invoice->delete();   // soft delete = CANCELLED

            \App\Services\Activity\ActivityLogger::log('redemption', 'cancelled',
                'Redemption ' . $invoice->invoice_no . ' CANCELLED' . ($reason ? ' — ' . $reason : '') . '; QR ' . ($reopenQr ? 'live again' : 'cancelled (redeemed before)') . ', stock returned', [
                    'subject' => $invoice,
                    'details' => ['grand_total' => (float) $invoice->grand_total, 'qr' => $invoice->qr->qr_code, 'pieces_returned' => -1 * (float) $moved->sum('qty_change')],
                ]);
        });

        if ($reopenQr && ! app()->runningUnitTests()) {
            \App\Services\Push\Notifier::to($invoice->member, 'redeem',
                'Redemption cancelled — ' . $invoice->invoice_no,
                'The redemption made at ' . ($invoice->branch?->name ?? 'the branch') . ' was cancelled. Your QR is active again and can be redeemed.',
                route: '/documents',
            );
        }
    }

    /** Gold / silver margin rows this redemption paid to its branch. */
    protected function margins(RedemptionInvoice $invoice)
    {
        return ResellerCommission::where('invoice_no', $invoice->invoice_no)
            ->where('branch_id', $invoice->branch_id)
            ->whereIn('com_type_id', [ResellerCommission::COM_GOLD_MARGIN, ResellerCommission::COM_SILVER_MARGIN]);
    }
}
