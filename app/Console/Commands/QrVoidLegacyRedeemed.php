<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\RedeemableQr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-20: "a QR already redeemed in the old system is ready to redeem in the new one".
 *
 * Cause: the roll-out carried over only the old system's PENDING QRs. A bill whose QR was already
 * REDEEMED there had no QR row here, so "Show QR" on its contract minted a fresh pending billing
 * QR (RedeemableQrService::forBond) — redeemable a second time. forBond no longer mints for an
 * old-system bill; this command clears the QRs minted that way before the fix.
 *
 *   php artisan qr:void-legacy-redeemed            dry run — counts + a CSV of every QR found
 *   php artisan qr:void-legacy-redeemed --apply    cancel the pending ones
 *
 * A re-minted billing QR = made by this system (source system) for a bill of the old system,
 * not a renewal / settlement QR (no Gold Saving collection saved with it, no settlement behind
 * it). They fall in four groups:
 *
 *   A  old-system QR of the bill REDEEMED            → cancelled
 *   B  old-system QR of the bill still pending       → cancelled (the member keeps the staged
 *      (staged here as a legacy QR — a duplicate)       legacy QR)
 *   C  the old system has no QR for the bill at all  → listed; cancelled only with --include-no-legacy-qr
 *   D  already REDEEMED again in this system         → listed only (a second redemption that
 *                                                       really happened — for the board to decide)
 *
 * Renewal and settlement QRs of old-system bills are new entitlements and are never touched.
 * Idempotent: a cancelled QR is not found again.
 */
class QrVoidLegacyRedeemed extends Command
{
    protected $signature = 'qr:void-legacy-redeemed {--apply : cancel the QRs (default = dry run)} {--include-no-legacy-qr : also cancel group C (old system has no QR for the bill)} {--remove-duplicates : also remove the second redemptions of group D whose QR the old system had redeemed (invoice, stock, margin, restock order)} {--connection=legacy : legacy DB connection}';

    protected $description = 'Cancel billing QRs minted here for old-system bills whose QR was already redeemed (or is still pending) in the old system';

    /** A Gold Saving collection saved within this many seconds of the QR = its renewal QR. */
    private const RENEWAL_WINDOW = 120;

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        $apply = (bool) $this->option('apply');
        $legacy = DB::connection((string) $this->option('connection'));

        // old-system invoice QRs per bill: [redeemed count, pending count, last redeem date]
        $old = [];
        foreach ($legacy->table('tbl_digi_queue')->where('route', 'INVOICE')->get(['refferance', 'deliverystatus', 'deliverydate']) as $q) {
            $k = (string) $q->refferance;
            $old[$k] ??= ['redeemed' => 0, 'pending' => 0, 'date' => null];
            if (strtoupper((string) $q->deliverystatus) === 'REDEEMED') {
                $old[$k]['redeemed']++;
                $old[$k]['date'] = max((string) $old[$k]['date'], (string) $q->deliverydate) ?: null;
            } else {
                $old[$k]['pending']++;
            }
        }
        $oldInvoice = $legacy->table('tbl_qrsalesinvoice')->pluck('estid', 'invno')->all();

        $settled = DB::table('rd_settlements')->whereNotNull('redeemable_qr_id')->pluck('redeemable_qr_id')
            ->merge(DB::table('contract_settlements')->whereNotNull('redeemable_qr_id')->pluck('redeemable_qr_id'))->flip();

        $qrs = DB::table('redeemable_qrs as q')
            ->join('bonds as b', 'b.id', '=', 'q.bond_id')
            ->leftJoin('plans as p', 'p.id', '=', 'b.plan_id')
            ->leftJoin('members as m', 'm.id', '=', 'q.member_id')
            ->leftJoin('branches as rb', 'rb.id', '=', 'q.redeem_branch_id')
            ->where('q.source', RedeemableQr::SOURCE_SYSTEM)
            ->whereIn('q.status', ['pending', 'redeemed'])
            ->where(fn ($w) => $w->whereNull('q.kind')->orWhere('q.kind', 'billing'))
            ->where('b.bond_date', '<', Bond::NEW_SYSTEM_FROM)
            ->where('b.invoice_no', 'not like', 'INV-%')
            ->orderBy('q.id')
            ->get(['q.id', 'q.bond_id', 'q.invoice_no', 'q.qr_code', 'q.status', 'q.cash_worth', 'q.created_at', 'q.redeemed_at',
                'p.code as plan', 'm.member_code', 'm.name as member', 'rb.name as redeem_branch']);

        $collections = DB::table('rd_entries')->whereIn('bond_id', $qrs->pluck('bond_id')->unique())
            ->get(['bond_id', 'created_at'])->groupBy('bond_id');

        $groups = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
        foreach ($qrs as $q) {
            if (isset($settled[$q->id])) {
                continue;
            }
            $at = strtotime((string) $q->created_at);
            $renewal = ($collections->get($q->bond_id) ?? collect())
                ->contains(fn ($e) => abs(strtotime((string) $e->created_at) - $at) <= self::RENEWAL_WINDOW);
            if ($renewal) {
                continue;   // the QR of a Gold Saving renewal paid here — a new entitlement
            }
            $o = $old[(string) $q->invoice_no] ?? ['redeemed' => 0, 'pending' => 0, 'date' => null];
            $q->old_state = match (true) {
                $o['pending'] > 0 => 'pending in old system (staged here)',
                $o['redeemed'] > 0 => 'redeemed in old system' . ($o['date'] ? ' on ' . $o['date'] : ''),
                default => 'no QR in old system',
            };
            $q->old_redemption_invoice = $oldInvoice[(string) $q->invoice_no] ?? null;
            $group = match (true) {
                $q->status === 'redeemed' => 'D',
                $o['pending'] > 0 => 'B',
                $o['redeemed'] > 0 => 'A',
                default => 'C',
            };
            $q->group = $group;
            $groups[$group][] = $q;
        }

        $void = array_merge($groups['A'], $groups['B'], $this->option('include-no-legacy-qr') ? $groups['C'] : []);
        $sum = fn (array $rows) => \App\Support\Money::group(array_sum(array_map(fn ($q) => (float) $q->cash_worth, $rows)));
        $this->table(['Group', 'What', 'QRs', 'Worth ₹', 'Action'], [
            ['A', 'pending here, QR of the bill already REDEEMED in the old system', count($groups['A']), $sum($groups['A']), 'cancel'],
            ['B', 'pending here, old-system QR still pending (duplicate of the staged QR)', count($groups['B']), $sum($groups['B']), 'cancel'],
            ['C', 'pending here, the old system has no QR for the bill', count($groups['C']), $sum($groups['C']), $this->option('include-no-legacy-qr') ? 'cancel' : 'listed only (--include-no-legacy-qr)'],
            ['D', 'ALREADY REDEEMED AGAIN in this system', count($groups['D']), $sum($groups['D']), 'listed only'],
        ]);

        $path = storage_path('app/qr-void-legacy-redeemed-' . now()->format('Ymd-His') . '.csv');
        $fh = fopen($path, 'w');
        fputcsv($fh, ['group', 'qr_id', 'qr_code', 'status', 'bill_no', 'plan', 'member_code', 'member', 'worth', 'minted_at', 'old_system', 'old_redemption_invoice', 'redeemed_here_at', 'redeemed_here_branch']);
        foreach ($groups as $rows) {
            foreach ($rows as $q) {
                fputcsv($fh, [$q->group, $q->id, $q->qr_code, $q->status, $q->invoice_no, $q->plan, $q->member_code, $q->member,
                    $q->cash_worth, $q->created_at, $q->old_state, $q->old_redemption_invoice, $q->redeemed_at, $q->redeem_branch]);
            }
        }
        fclose($fh);
        $this->line('List written to ' . $path);

        if ($this->option('remove-duplicates')) {
            // the second redemption of a QR the old system had already redeemed (not the bills
            // the old system has no QR for — those stay, like group C)
            $this->removeDuplicates(array_values(array_filter($groups['D'],
                fn ($q) => ! str_starts_with($q->old_state, 'no QR'))), $apply);
        }

        if (! $apply) {
            $this->info('DRY RUN: ' . count($void) . ' pending QR(s) would be cancelled. Nothing written — run with --apply.');

            return self::SUCCESS;
        }

        $ids = array_map(fn ($q) => $q->id, $void);
        $done = 0;
        DB::transaction(function () use ($ids, &$done) {
            foreach (array_chunk($ids, 500) as $chunk) {
                // status re-checked: a QR redeemed since the list was read is left alone
                $done += DB::table('redeemable_qrs')->whereIn('id', $chunk)->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'otp' => null, 'otp_sent_at' => null, 'updated_at' => now()]);
            }
        });
        \App\Services\Activity\ActivityLogger::log('redemption', 'cancelled',
            $done . ' billing QR(s) minted here for old-system bills CANCELLED (the old system had already issued the QR of those bills)',
            ['details' => ['qr_ids' => $ids, 'list' => basename($path)]]);
        $this->info("APPLY: $done pending QR(s) cancelled — they can no longer be read or redeemed.");

        return self::SUCCESS;
    }

    /**
     * Board 2026-11-20: "remove the duplicated data — the invoices and any stock claimed against
     * them — based on the date". Of two redemptions of one bill's QR the EARLIER one (the old
     * system's) stands; the later one, made here, is removed as if it never happened:
     *
     *   restock order   pending → cancelled; approved → the pieces it shipped leave the branch
     *                   again (and return to the supplier it shipped from), order cancelled
     *   invoice         cancelled (kept on record with its number, out of every register / GST)
     *   stock           the pieces the redemption gave out return to the redeeming branch
     *   margin          its unpaid gold / silver margin is taken back
     *   QR              cancelled — never live again
     *
     * One invoice = one transaction. Left alone and reported: a redemption that opened a dealer
     * account, one whose margin is already approved / paid, and one whose branch would end with
     * negative stock.
     *
     * @param  array<int, object>  $qrs
     */
    protected function removeDuplicates(array $qrs, bool $apply): void
    {
        $invoices = \App\Models\RedemptionInvoice::with('branch', 'restockOrder')
            ->whereIn('redeemable_qr_id', array_map(fn ($q) => $q->id, $qrs))->whereNull('legacy_restock')->orderBy('id')->get();
        $byQr = collect($qrs)->keyBy('id');
        $cancel = app(\App\Services\Redeem\RedemptionCancelService::class);
        $rows = [];
        $removed = 0;

        foreach ($invoices as $invoice) {
            $q = $byQr[$invoice->redeemable_qr_id];
            $order = $invoice->restockOrder;
            $orderState = $order ? $order->request_no . ' (' . $order->status . ')' : '—';
            $result = 'would be removed';
            try {
                DB::transaction(function () use ($invoice, $order, $cancel, $q, $apply) {
                    $touched = [];
                    if ($order && ! in_array($order->status, ['rejected', 'cancelled'], true)) {
                        $touched = $this->undoRestockOrder($order, $invoice);
                    }
                    $cancel->cancel($invoice, null, 'Duplicate — the QR of bill ' . $q->invoice_no . ' was ' . $q->old_state, false);
                    foreach ($touched as [$branchId, $productId]) {
                        $left = (float) \App\Models\Stock::where('branch_id', $branchId)->where('catalog_product_id', $productId)->value('quantity');
                        abort_if($left < -1e-6, 422, 'branch stock would go negative — the claimed stock is already used');
                    }
                    if (! $apply) {
                        throw new DryRunRollback;
                    }
                });
                $result = 'REMOVED';
                $removed++;
            } catch (DryRunRollback) {
                // every step ran and was rolled back — the removal is possible
            } catch (\Throwable $e) {
                $result = 'LEFT: ' . $e->getMessage();
            }
            $rows[] = [$invoice->invoice_no, \Illuminate\Support\Carbon::parse($invoice->invoice_date)->toDateString(), $invoice->branch?->name, \App\Support\Money::group((float) $invoice->grand_total),
                $q->invoice_no, $q->old_state, $orderState, $result];
        }

        $this->newLine();
        $this->line('Duplicate redemptions (the later one of two — made here after the old system had redeemed the bill):');
        $this->table(['Invoice', 'Date', 'Branch', 'Grand ₹', 'Bill', 'Old system', 'Restock order', 'Result'], $rows);
        $this->info($apply
            ? "APPLY: $removed duplicate redemption(s) removed — invoice cancelled, stock and margin put back, restock order undone, QR cancelled."
            : 'DRY RUN: ' . count(array_filter($rows, fn ($r) => $r[7] === 'would be removed')) . ' duplicate redemption(s) would be removed.');
    }

    /**
     * Undo the restock order of a duplicate redemption: nothing may be claimed for it. An approved
     * order's stock movements are reversed one by one (the receiving branch gives the pieces
     * back, the shipping branch gets them back). Returns the [branch, product] pairs touched.
     *
     * @return array<int, array{0:int, 1:int}>
     */
    protected function undoRestockOrder(\App\Models\BranchOrderRequest $order, \App\Models\RedemptionInvoice $invoice): array
    {
        $touched = [];
        if ($order->status === 'approved') {
            $moves = \App\Models\StockMovement::where('ref_type', 'branch_order')->where('ref_id', $order->id)->get();
            foreach ($moves as $m) {
                $stock = \App\Models\Stock::where('branch_id', $m->branch_id)->where('catalog_product_id', $m->catalog_product_id)->lockForUpdate()->first()
                    ?? \App\Models\Stock::create(['branch_id' => $m->branch_id, 'catalog_product_id' => $m->catalog_product_id, 'quantity' => 0]);
                $stock->increment('quantity', -1 * (float) $m->qty_change);
                \App\Models\StockMovement::create([
                    'branch_id' => $m->branch_id,
                    'catalog_product_id' => $m->catalog_product_id,
                    'type' => 'adjustment',
                    'qty_change' => -1 * (float) $m->qty_change,
                    'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'branch_order_undo',
                    'ref_id' => $order->id,
                    'note' => 'Restock order ' . $order->request_no . ' undone — duplicate redemption ' . $invoice->invoice_no,
                    'moved_on' => now()->toDateString(),
                ]);
                $touched[] = [(int) $m->branch_id, (int) $m->catalog_product_id];
            }
        }
        $was = $order->status;
        $order->update([
            'status' => 'cancelled', 'cancelled_at' => now(),
            'decision_note' => 'Duplicate redemption ' . $invoice->invoice_no . ' — its QR had already been redeemed',
        ]);
        \App\Services\Activity\ActivityLogger::log('orders', 'cancelled',
            'Order ' . $order->request_no . ' (' . $was . ') cancelled — restock of duplicate redemption ' . $invoice->invoice_no
                . ($was === 'approved' ? '; the stock it shipped was taken back' : ''),
            ['subject' => $order, 'details' => ['order' => $order->request_no, 'branch_id' => $order->branch_id, 'was' => $was]]);

        return $touched;
    }
}

/** Thrown inside a transaction to roll a rehearsed removal back (dry run). */
class DryRunRollback extends \RuntimeException {}
