<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-16 (LEG-28945): the pending "REDEEM" orders carried over from the old system are
 * the RESTOCK orders of old redemption invoices, but nothing tied the two together — the invoice
 * read "Re-Stocked" while its LEG- order was still waiting, the same invoice could carry two
 * pending orders (approving both restocks twice), and an order that lost its cart in the old
 * system was never brought over.
 *
 * For every old-system pending REDEEM order (tbl_brorderrequest.stockflag = redemption invoice,
 * paymentremarks = its sale invoice no) the order is compared with the redemption invoice —
 * same branch, same amount, same cart (product → pieces):
 *
 *   same            → kept under Order Requests as "Legacy (pre cut-over)" and LINKED to the invoice
 *   cart differs    → the order lines are re-made from the invoice's cart, then linked
 *   order lost its cart, invoice has one → the order is RESTORED from the invoice's cart, linked
 *   neither has a cart → cannot be restored; listed for a manual decision
 *   second order for the same invoice → cancelled as a duplicate (the newest matching one is kept)
 *
 * The link is branch_order_requests.redemption_invoice_id (UNIQUE) — a second restock order for an
 * invoice is refused by the database. Old-system CASH lines that lost their catalog product are
 * re-attached to the CASH product (an approved cash order otherwise adds nothing).
 *
 *   php artisan legacy:link-restock-orders            dry run — what would happen
 *   php artisan legacy:link-restock-orders --apply    write it (idempotent, safe to re-run)
 */
class LegacyLinkRestockOrders extends Command
{
    protected $signature = 'legacy:link-restock-orders {--apply : write the changes (default = dry run)} {--connection=legacy : legacy DB connection}';

    protected $description = 'Compare old-system restock orders with their redemption invoices: link the matching ones, restore / re-make from the invoice cart, cancel duplicates';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        $apply = (bool) $this->option('apply');
        $legacy = DB::connection((string) $this->option('connection'));

        if (! \Illuminate\Support\Facades\Schema::hasColumn('branch_order_requests', 'redemption_invoice_id')) {
            $this->error('Run "php artisan migrate" first — branch_order_requests.redemption_invoice_id is missing.');

            return self::FAILURE;
        }

        $this->removeCashOrders($apply);
        $this->realignBranches($legacy, $apply);

        $legacyInvoices = $legacy->table('tbl_qrsalesinvoice')->get(['estid', 'invno', 'branchid', 'grandtotal'])->keyBy('estid');
        $invoices = DB::table('redemption_invoices')->whereNotNull('legacy_restock')
            ->get(['id', 'invoice_no', 'branch_id', 'grand_total'])->keyBy('invoice_no');
        // The invoice's VALID cart: the lines that add up to the invoice's taxable total. Several
        // old redemption invoices of one sale share that sale's cart, so an imported invoice can
        // carry its siblings' lines too — then the one set of lines that makes the total is taken.
        $taxable = DB::table('redemption_invoices')->whereNotNull('legacy_restock')->pluck('taxable_total', 'id')->all();
        $rawLines = [];
        foreach (DB::table('redemption_lines')->whereIn('redemption_invoice_id', $invoices->pluck('id'))->orderBy('id')->get() as $l) {
            $rawLines[$l->redemption_invoice_id][] = $l;
        }
        $invoiceCarts = [];   // invoice id => [catalog_product_id => pieces]   (valid carts only)
        $invoiceLines = [];   // invoice id => the lines of the valid cart
        $invalidCart = [];    // invoice id => true: has lines, but they do not add up
        $trimIds = [];        // redemption_lines that belong to a sibling invoice of the same sale
        $trimmedInvoices = 0;
        foreach ($rawLines as $invoiceId => $lines) {
            $valid = $this->validLines($lines, (float) ($taxable[$invoiceId] ?? 0));
            if ($valid === null) {
                $invalidCart[$invoiceId] = true;

                continue;
            }
            if (count($valid) < count($lines)) {
                $trimmedInvoices++;
                $keep = array_flip(array_map(fn ($l) => $l->id, $valid));
                foreach ($lines as $l) {
                    if (! isset($keep[$l->id])) {
                        $trimIds[] = $l->id;
                    }
                }
            }
            $invoiceLines[$invoiceId] = $valid;
            foreach ($valid as $l) {
                $key = (int) $l->catalog_product_id;
                $invoiceCarts[$invoiceId][$key] = ($invoiceCarts[$invoiceId][$key] ?? 0) + $this->pieces($l);
            }
        }
        // User 2026-11-16: trim them — an old redemption invoice keeps only the lines that make its total.
        $this->line('Redemption invoices carrying a sibling invoice\'s lines: ' . $trimmedInvoices . ' (' . count($trimIds) . ' extra line(s))'
            . ($trimIds ? ($apply ? ' — trimmed.' : ' — would be trimmed.') : ''));
        if ($apply) {
            foreach (array_chunk($trimIds, 500) as $chunk) {
                DB::table('redemption_lines')->whereIn('id', $chunk)->delete();
            }
        }

        $orders = DB::table('branch_order_requests')->where('source', 'legacy')
            ->get(['id', 'request_no', 'source_ref', 'status', 'redemption_invoice_id', 'payment_remarks'])->keyBy('source_ref');
        $orderCarts = [];
        foreach (DB::table('branch_order_lines')->whereIn('order_request_id', $orders->pluck('id'))->get(['order_request_id', 'catalog_product_id', 'weight']) as $l) {
            if ($l->catalog_product_id) {
                $key = (int) $l->catalog_product_id;
                $orderCarts[$l->order_request_id][$key] = ($orderCarts[$l->order_request_id][$key] ?? 0) + (float) $l->weight;
            }
        }
        $linkedInvoices = DB::table('branch_order_requests')->whereNotNull('redemption_invoice_id')->pluck('id', 'redemption_invoice_id')->all();
        $userByBranch = DB::table('users')->whereNotNull('branch_id')->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'branch_id'])->groupBy('branch_id')->map(fn ($g) => (int) $g->first()->id)->all();

        // group the old-system pending restock orders by the redemption invoice they restore
        $approvedInLegacy = $legacy->table('tbl_brorderrequest')->where('status', 'APPROVED')->where('paymode', 'REDEEM')
            ->orderBy('transid')->get(['transid', 'stockflag', 'doorder'])->keyBy(fn ($r) => (string) $r->stockflag)->all();
        $groups = [];
        $orphans = [];
        $removedEarlier = 0;
        foreach ($legacy->table('tbl_brorderrequest')->where('status', 'PENDING')->where('paymode', 'REDEEM')->orderBy('transid')->get() as $o) {
            $li = $legacyInvoices[(int) $o->stockflag] ?? null;
            $inv = $li ? ($invoices[(string) (int) $li->estid] ?? null) : null;
            if ($li && ! $inv && ! isset($orders[(int) $o->transid]) && ! isset($orders[(string) $o->transid])) {
                $removedEarlier++;   // the mistaken redemption this command already removed (invoice + order gone)

                continue;
            }
            if (! $li || ! $inv || trim((string) $li->invno) !== trim((string) $o->paymentremarks)) {
                $orphans[] = [$o->transid, $o->doorder, $o->stockflag, $o->paymentremarks, ! $li ? 'no such redemption invoice in the old system'
                    : (! $inv ? 'redemption invoice ' . $li->estid . ' not imported (run legacy:import-redemptions)' : 'invoice number on the order does not match')];
                continue;
            }
            $groups[$inv->id][] = ['o' => $o, 'li' => $li, 'inv' => $inv];
        }

        $count = ['linked' => 0, 'unverified' => 0, 'already' => 0, 'remade' => 0, 'restored' => 0, 'duplicate' => 0, 'unrestorable' => 0];
        $report = ['unverified' => [], 'remade' => [], 'restored' => [], 'duplicate' => [], 'unrestorable' => []];

        foreach ($groups as $invoiceId => $members) {
            $inv = $members[0]['inv'];
            $invCart = $invoiceCarts[$invoiceId] ?? [];
            ksort($invCart);
            $invoiceHasCart = $invCart !== [];

            // verdict per order
            $pool = [];
            foreach ($members as $m) {
                $o = $m['o'];
                $row = $orders[(string) $o->transid] ?? ($orders[(int) $o->transid] ?? null);
                if ($row && in_array($row->status, ['rejected', 'cancelled'], true)) {
                    continue;   // already decided here — out of the pool
                }
                $cart = $row ? ($orderCarts[$row->id] ?? []) : [];
                ksort($cart);
                $sameHeader = (string) $m['li']->branchid === (string) $o->brid && abs((float) $m['li']->grandtotal - (float) $o->nettotal) < 0.5;
                $verdict = $cart === [] ? 'no_cart' : ($sameHeader && $invoiceHasCart && $this->sameCart($cart, $invCart) ? 'same'
                    : (! $invoiceHasCart && $sameHeader ? 'unverified' : 'differs'));
                $pool[] = ['o' => $o, 'row' => $row, 'verdict' => $verdict];
            }
            if ($pool === []) {
                continue;
            }

            // The same invoice already has an APPROVED restock order in the old system: the stock
            // went back to the branch there. A pending one on top of it is a second restock.
            if ($done = $approvedInLegacy[(string) $members[0]['o']->stockflag] ?? null) {
                foreach ($pool as $p) {
                    $count['duplicate']++;
                    $report['duplicate'][] = ['LEG-' . $p['o']->transid, $p['o']->doorder, $inv->invoice_no, number_format((float) $p['o']->nettotal, 2),
                        'already restocked in the old system: order ' . $done->transid . ' approved, ' . $done->doorder
                        . ($p['row'] ? ($p['row']->status === 'pending' ? '' : ' — ALREADY ' . strtoupper($p['row']->status) . ' HERE, check by hand') : ' — never imported, nothing to cancel')];
                    if ($apply && $p['row'] && $p['row']->status === 'pending') {
                        DB::table('branch_order_requests')->where('id', $p['row']->id)->where('status', 'pending')->update([
                            'status' => 'cancelled', 'cancelled_at' => now(), 'redemption_invoice_id' => null,
                            'decision_note' => 'Duplicate — redemption invoice ' . $inv->invoice_no . ' was already restocked in the old system (order ' . $done->transid . ', approved, ' . $done->doorder . ')',
                            'updated_at' => now(),
                        ]);
                    }
                }

                continue;
            }

            // the keeper: one already decided / linked here, else the newest matching order, else
            // the newest order that can be put right from the invoice's cart
            $rank = function (array $p) use ($invoiceId, $invoiceHasCart): int {
                $row = $p['row'];
                if ($row && (int) $row->redemption_invoice_id === (int) $invoiceId) {
                    return 5;
                }
                if ($row && $row->status !== 'pending') {
                    return 4;   // approved / in transit / delivered on the new system already
                }
                if ($p['verdict'] === 'same') {
                    return 3;
                }
                if ($p['verdict'] === 'unverified') {
                    return 2;   // has its own cart, the invoice has none to compare with
                }

                return $invoiceHasCart ? 1 : 0;   // re-make / restore from the invoice — or impossible
            };
            usort($pool, fn ($a, $b) => [$rank($b), (int) $b['o']->transid] <=> [$rank($a), (int) $a['o']->transid]);
            $keeper = array_shift($pool);
            $keepRank = $rank($keeper);

            if ($keepRank === 0) {
                // No order of this invoice has a cart and the invoice has none either: a mistaken
                // redemption of the first build, put right by hand at the time (user 2026-11-16)
                // — the invoice and its order request are REMOVED. An invoice that does carry
                // lines (they just do not add up) is never removed.
                $removable = ! isset($rawLines[$invoiceId]) && ! isset($linkedInvoices[$invoiceId]);
                foreach (array_merge([$keeper], $pool) as $p) {
                    $count['unrestorable']++;
                    $report['unrestorable'][] = ['LEG-' . $p['o']->transid, $p['o']->doorder, $inv->invoice_no, number_format((float) $p['o']->nettotal, 2),
                        $removable ? 'no cart on the order or on the invoice — invoice + order removed' : 'no cart on the order; the invoice is kept (it has lines or an order)'];
                    if ($apply && $removable && $p['row'] && $p['row']->status === 'pending') {
                        $this->deleteOrders([$p['row']->id]);
                    }
                }
                if ($apply && $removable) {
                    DB::table('redemption_invoices')->where('id', $invoiceId)->whereNotNull('legacy_restock')->delete();
                }
                continue;
            }
            if (isset($linkedInvoices[$invoiceId]) && (! $keeper['row'] || (int) $linkedInvoices[$invoiceId] !== (int) $keeper['row']->id)) {
                // another order (e.g. a new-system restock) already holds this invoice — everything here is a duplicate
                array_unshift($pool, $keeper);
                $keeper = null;
            }

            if ($keeper) {
                $o = $keeper['o'];
                if ($keepRank === 5) {
                    $count['already']++;
                } elseif ($keepRank >= 2) {
                    $count[$keepRank === 2 ? 'unverified' : 'linked']++;
                    if ($keepRank === 2) {
                        $report['unverified'][] = ['LEG-' . $o->transid, $o->doorder, $inv->invoice_no, number_format((float) $o->nettotal, 2),
                            $this->cartText($orderCarts[$keeper['row']->id] ?? []) . (isset($invalidCart[$invoiceId]) ? ' — invoice lines do not add up to its total' : ' — invoice has no lines')];
                    }
                    if ($apply) {
                        DB::table('branch_order_requests')->where('id', $keeper['row']->id)->update(['redemption_invoice_id' => $invoiceId, 'updated_at' => now()]);
                    }
                } elseif ($keeper['row']) {
                    $count['remade']++;
                    $report['remade'][] = ['LEG-' . $o->transid, $o->doorder, $inv->invoice_no, number_format((float) $o->nettotal, 2),
                        $this->cartText($orderCarts[$keeper['row']->id] ?? []) . '  →  ' . $this->cartText($invCart)];
                    if ($apply) {
                        DB::transaction(function () use ($keeper, $invoiceId, $inv, $invoiceLines, $o) {
                            DB::table('branch_order_lines')->where('order_request_id', $keeper['row']->id)->delete();
                            $n = $this->insertLines($keeper['row']->id, $invoiceLines[$invoiceId], (float) $o->gst);
                            DB::table('branch_order_requests')->where('id', $keeper['row']->id)->update([
                                'redemption_invoice_id' => $invoiceId, 'no_of_items' => $n,
                                'payment_remarks' => mb_substr(trim((string) $keeper['row']->payment_remarks) . ' · lines re-made from redemption invoice ' . $inv->invoice_no, 0, 255),
                                'updated_at' => now(),
                            ]);
                        });
                    }
                } else {
                    $count['restored']++;
                    $report['restored'][] = ['LEG-' . $o->transid, $o->doorder, $inv->invoice_no, number_format((float) $o->nettotal, 2), $this->cartText($invCart)];
                    if ($apply) {
                        DB::transaction(function () use ($o, $inv, $invoiceId, $invoiceLines, $userByBranch) {
                            $grand = round((float) $o->nettotal, 2);
                            $cross = round((float) $o->ordercost ?: $grand, 2);
                            $placed = substr((string) ($o->placement ?: $o->doorder), 0, 10) ?: now()->toDateString();
                            $remarks = trim((string) $o->paymentremarks) . ' · Legacy order ' . $o->transid . ' of ' . $o->doorder
                                . ' · payment received in legacy (RO) · lines restored from redemption invoice ' . $inv->invoice_no;
                            $id = DB::table('branch_order_requests')->insertGetId([
                                'request_no' => 'LEG-' . $o->transid, 'source' => 'legacy', 'source_ref' => (int) $o->transid,
                                'redemption_invoice_id' => $invoiceId, 'branch_id' => $inv->branch_id,
                                'requested_by' => $userByBranch[$inv->branch_id] ?? null, 'no_of_items' => 0,
                                'cross_total' => $cross, 'gst_total' => round(max(0, $grand - $cross), 2), 'grand_total' => $grand,
                                'payment_type' => 'others', 'payment_remarks' => mb_substr($remarks, 0, 255),
                                'paid_amount' => $grand, 'pay_cash' => $grand, 'status' => 'pending',
                                'created_at' => $placed . ' 00:00:00', 'updated_at' => now(),
                            ]);
                            $n = $this->insertLines($id, $invoiceLines[$invoiceId], (float) $o->gst);
                            DB::table('branch_order_requests')->where('id', $id)->update(['no_of_items' => $n]);
                        });
                    }
                }
            }

            // every other order of the same invoice is a duplicate
            foreach ($pool as $p) {
                $count['duplicate']++;
                $keptNo = $keeper ? 'LEG-' . $keeper['o']->transid : '#' . ($linkedInvoices[$invoiceId] ?? '?');
                $report['duplicate'][] = ['LEG-' . $p['o']->transid, $p['o']->doorder, $inv->invoice_no, number_format((float) $p['o']->nettotal, 2),
                    'kept ' . $keptNo . ($p['row'] ? ($p['row']->status === 'pending' ? '' : ' — ALREADY ' . strtoupper($p['row']->status) . ' HERE, check by hand') : ' — never imported, nothing to cancel')];
                if ($apply && $p['row'] && $p['row']->status === 'pending') {
                    DB::table('branch_order_requests')->where('id', $p['row']->id)->where('status', 'pending')->update([
                        'status' => 'cancelled', 'cancelled_at' => now(), 'redemption_invoice_id' => null,
                        'decision_note' => 'Duplicate of ' . $keptNo . ' — the same redemption invoice ' . $inv->invoice_no . ' was ordered twice in the old system',
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->newLine();
        $this->table(['Old-system pending restock orders', 'Count'], [
            ['same as the invoice → kept + linked', $count['linked']],
            ['kept + linked, but the invoice has no valid cart to compare with', $count['unverified']],
            ['already linked (earlier run)', $count['already']],
            ['cart differed → lines re-made from the invoice', $count['remade']],
            ['order had lost its cart → restored from the invoice', $count['restored']],
            ['duplicate (second order for the invoice, or already restocked in the old system) → cancelled', $count['duplicate']],
            ['no cart anywhere → redemption invoice + order removed', $count['unrestorable']],
            ['removed by an earlier run (no cart anywhere)', $removedEarlier],
            ['no matching redemption invoice', count($orphans)],
        ]);
        foreach (['unverified' => 'Linked without a cart check — look before approving', 'remade' => 'Lines re-made from the invoice', 'restored' => 'Restored from the invoice', 'duplicate' => 'Duplicates', 'unrestorable' => 'No cart anywhere — removed'] as $key => $title) {
            if ($report[$key]) {
                $this->line('<comment>' . $title . '</comment>');
                $this->table(['Order', 'Ordered', 'Redeem inv', '₹', 'Note'], $report[$key]);
            }
        }
        if ($orphans) {
            $this->line('<comment>No matching redemption invoice</comment>');
            $this->table(['Legacy order', 'Ordered', 'stockflag', 'remarks', 'Why'], $orphans);
        }
        $this->verify($apply);
        $this->line($apply ? '<info>Applied.</info>' : '<comment>Dry run — nothing written. Re-run with --apply.</comment>');

        return self::SUCCESS;
    }

    /**
     * User 2026-11-16: every old-system CASH request is removed (a branch that still needs cash
     * stock asks again on the Order Form, with proof) — and an order that mixes cash with goods
     * goes with them. Only PENDING ones; a decided order is history.
     */
    protected function removeCashOrders(bool $apply): void
    {
        $rows = DB::table('branch_order_requests as r')->where('r.source', 'legacy')->where('r.status', 'pending')
            ->whereExists(fn ($q) => $q->from('branch_order_lines as l')->whereColumn('l.order_request_id', 'r.id')->where('l.material', 'cash'))
            ->get(['r.id', 'r.request_no', 'r.grand_total']);
        $mixed = $rows->isEmpty() ? collect() : DB::table('branch_order_lines')->whereIn('order_request_id', $rows->pluck('id'))
            ->where('material', '<>', 'cash')->distinct()->pluck('order_request_id');
        $this->line(sprintf('Old-system CASH requests (pending): %d, ₹%s — of which %d mix cash with goods (%s)%s',
            $rows->count(), \App\Support\Money::group((float) $rows->sum('grand_total')), $mixed->count(),
            $rows->whereIn('id', $mixed)->pluck('request_no')->implode(', ') ?: '—',
            $rows->isEmpty() ? '' : ($apply ? ' — removed.' : ' — would be removed.')));
        if ($apply) {
            $this->deleteOrders($rows->pluck('id')->all());
        }
    }

    /**
     * Approve puts the stock into the ORDERING branch, so the order must sit on the branch that
     * placed it. legacy:rollout mapped old branches by name — a branch that shared its name with
     * another (later split apart, each keeping its own `legacy_brid`) left its orders on the
     * shared one (often Head Office). Move every pending old-system order to the branch its old
     * brid belongs to, with that branch's login as the requester.
     */
    protected function realignBranches(\Illuminate\Database\Connection $legacy, bool $apply): void
    {
        $byBrid = DB::table('branches')->whereNotNull('legacy_brid')->pluck('id', 'legacy_brid')->all();
        $userByBranch = DB::table('users')->whereNotNull('branch_id')->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'branch_id'])->groupBy('branch_id')->map(fn ($g) => (int) $g->first()->id)->all();
        $brid = $legacy->table('tbl_brorderrequest')->where('status', 'PENDING')->pluck('brid', 'transid')->all();
        $moved = 0;
        $unmapped = 0;
        foreach (DB::table('branch_order_requests')->where('source', 'legacy')->where('status', 'pending')->get(['id', 'source_ref', 'branch_id', 'requested_by']) as $r) {
            if (! isset($brid[(int) $r->source_ref])) {
                continue;
            }
            $want = $byBrid[(int) $brid[(int) $r->source_ref]] ?? null;
            if (! $want) {
                $unmapped++;

                continue;
            }
            if ((int) $want === (int) $r->branch_id) {
                continue;
            }
            $moved++;
            if ($apply) {
                DB::table('branch_order_requests')->where('id', $r->id)->update([
                    'branch_id' => $want, 'requested_by' => $userByBranch[$want] ?? $r->requested_by, 'updated_at' => now(),
                ]);
            }
        }
        $this->line('Pending old-system orders sitting on a branch that did not place them: ' . $moved
            . ($moved ? ($apply ? ' — moved to their own branch.' : ' — would be moved to their own branch.') : '')
            . ($unmapped ? ' (' . $unmapped . ' with an old branch id no branch carries — left)' : ''));
    }

    /** Hard-delete order requests with everything hanging off them. */
    protected function deleteOrders(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::transaction(function () use ($chunk) {
                foreach (['branch_order_lines', 'branch_order_attachments', 'branch_order_events'] as $table) {
                    DB::table($table)->whereIn('order_request_id', $chunk)->delete();
                }
                DB::table('branch_order_requests')->whereIn('id', $chunk)->delete();
            });
        }
    }

    /**
     * What Approve will do with the old-system orders still pending: each puts its lines, counted
     * in PIECES, into the ORDERING branch's own stock. Checks every line is fit for that, and that
     * a linked order still agrees with its redemption invoice (branch + cart).
     */
    protected function verify(bool $apply): void
    {
        $orders = DB::table('branch_order_requests')->where('source', 'legacy')->where('status', 'pending')
            ->get(['id', 'request_no', 'branch_id', 'redemption_invoice_id', 'grand_total']);
        $lines = DB::table('branch_order_lines')->whereIn('order_request_id', $orders->pluck('id'))
            ->get(['order_request_id', 'catalog_product_id', 'material', 'weight'])->groupBy('order_request_id');
        $invoices = DB::table('redemption_invoices')->whereIn('id', $orders->pluck('redemption_invoice_id')->filter())
            ->get(['id', 'invoice_no', 'branch_id', 'taxable_total', 'grand_total'])->keyBy('id');
        $invLines = DB::table('redemption_lines')->whereIn('redemption_invoice_id', $invoices->keys())->orderBy('id')->get()->groupBy('redemption_invoice_id');
        $branches = DB::table('branches')->pluck('is_active', 'id')->all();
        $names = DB::table('catalog_products')->pluck('name', 'id')->map(fn ($n) => json_decode((string) $n, true)['en'] ?? (string) $n)->all();

        $bad = [];
        $ok = 0;
        $pieces = [];
        foreach ($orders as $o) {
            $why = [];
            $cart = [];
            $ls = $lines[$o->id] ?? collect();
            if ($ls->isEmpty()) {
                $why[] = 'no lines';
            }
            foreach ($ls as $l) {
                if (! $l->catalog_product_id) {
                    $why[] = 'a line has no catalog product';
                } elseif ($l->material === 'cash') {
                    $why[] = 'cash line';
                } elseif ((float) $l->weight <= 0 || abs((float) $l->weight - round((float) $l->weight)) > 0.0001) {
                    $why[] = 'pieces not a whole number (' . (float) $l->weight . ')';
                } else {
                    $cart[(int) $l->catalog_product_id] = ($cart[(int) $l->catalog_product_id] ?? 0) + (float) $l->weight;
                }
            }
            if (! isset($branches[$o->branch_id])) {
                $why[] = 'ordering branch missing';
            }
            if ($o->redemption_invoice_id) {
                $inv = $invoices[$o->redemption_invoice_id] ?? null;
                if (! $inv) {
                    $why[] = 'linked invoice missing';
                } else {
                    if ((int) $inv->branch_id !== (int) $o->branch_id) {
                        $why[] = 'order branch ≠ invoice branch';
                    }
                    if (abs((float) $inv->grand_total - (float) $o->grand_total) > 0.5) {
                        $why[] = 'request value ≠ invoice value (' . number_format((float) $inv->grand_total, 2) . ')';
                    }
                    $valid = $this->validLines(($invLines[$inv->id] ?? collect())->all(), (float) $inv->taxable_total);
                    if ($valid === null) {
                        $why[] = 'invoice ' . $inv->invoice_no . ' lines do not add up (cart not checked)';
                    } else {
                        $invCart = [];
                        foreach ($valid as $l) {
                            $invCart[(int) $l->catalog_product_id] = ($invCart[(int) $l->catalog_product_id] ?? 0) + $this->pieces($l);
                        }
                        ksort($cart);
                        ksort($invCart);
                        if (! $this->sameCart($cart, $invCart)) {
                            $why[] = 'cart ≠ invoice ' . $inv->invoice_no . ' (' . $this->cartText($invCart) . ')';
                        }
                    }
                }
            }
            if ($why) {
                $bad[] = [$o->request_no, $o->redemption_invoice_id ? 'restock' : 'purchase', number_format((float) $o->grand_total, 2), $this->cartText($cart), implode('; ', array_unique($why))];
            } else {
                $ok++;
                foreach ($cart as $productId => $n) {
                    $pieces[$productId] = ($pieces[$productId] ?? 0) + $n;
                }
            }
        }

        $this->newLine();
        $this->line('<comment>Check before approval' . ($apply ? '' : ' (state BEFORE this run is applied)') . '</comment>');
        $this->line(sprintf('Pending old-system orders: %d — restock orders linked to a redemption invoice: %d, plain purchase orders: %d',
            $orders->count(), $orders->whereNotNull('redemption_invoice_id')->count(), $orders->whereNull('redemption_invoice_id')->count()));
        $this->line('Correct (product set, whole pieces, own branch, request value = invoice value, same cart as the invoice): ' . $ok . ' order(s); to look at: ' . count($bad));
        if ($bad) {
            $this->table(['Order', 'Kind', '₹', 'Lines (pieces)', 'Why'], $bad);
        }
        arsort($pieces);
        $this->line('Approving all the correct ones adds to the ordering branches: ' . (implode(', ', array_map(
            fn ($id, $n) => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') . ' × ' . ($names[$id] ?? '#' . $id), array_keys($pieces), $pieces)) ?: '—'));
    }

    /** Pieces on a redemption line — lines imported before 2026-11-13 (no HSN) keep the count in unit_weight. */
    protected function pieces(object $line): float
    {
        return $line->hsn_code === null && (float) $line->unit_weight > 0 && (float) $line->quantity <= 0
            ? (float) $line->unit_weight
            : ((float) $line->quantity > 0 ? (float) $line->quantity : (float) $line->unit_weight);
    }

    /**
     * The lines that make up the invoice: all of them when they add up to the taxable total, else
     * the one sub-set that does (same product → pieces whichever way it is picked). Null = the
     * lines cannot be trusted (a line without a product, or nothing adds up / it is ambiguous).
     *
     * @return array<int, object>|null
     */
    protected function validLines(array $lines, float $taxable): ?array
    {
        foreach ($lines as $l) {
            if (! $l->catalog_product_id || $this->pieces($l) <= 0) {
                return null;
            }
        }
        $total = fn (object $l) => (float) $l->line_total > 0 ? (float) $l->line_total : (float) $l->amount;
        if (abs(array_sum(array_map($total, $lines)) - $taxable) <= 1) {
            return $lines;
        }
        $n = count($lines);
        if ($taxable <= 0 || $n > 16) {
            return null;
        }
        $found = [];
        for ($mask = 1; $mask < (1 << $n) - 1; $mask++) {
            $sum = 0.0;
            $pick = [];
            $cart = [];
            for ($b = 0; $b < $n; $b++) {
                if ($mask & (1 << $b)) {
                    $sum += $total($lines[$b]);
                    $pick[] = $lines[$b];
                    $key = (int) $lines[$b]->catalog_product_id;
                    $cart[$key] = ($cart[$key] ?? 0) + $this->pieces($lines[$b]);
                }
            }
            if (abs($sum - $taxable) <= 1) {
                ksort($cart);
                $found[json_encode($cart)] ??= $pick;
            }
        }

        return count($found) === 1 ? array_values($found)[0] : null;
    }

    protected function sameCart(array $a, array $b): bool
    {
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (abs($v - $b[$k]) > 0.0001) {
                return false;
            }
        }

        return true;
    }

    protected function cartText(array $cart): string
    {
        static $names = null;
        $names ??= DB::table('catalog_products')->pluck('name', 'id')->map(fn ($n) => json_decode((string) $n, true)['en'] ?? (string) $n)->all();
        $parts = [];
        foreach ($cart as $productId => $pieces) {
            $parts[] = rtrim(rtrim(number_format($pieces, 3, '.', ''), '0'), '.') . ' × ' . ($names[$productId] ?? '#' . $productId);
        }

        return $parts ? implode(', ', $parts) : '(no lines)';
    }

    /** Order lines in the old-system shape (weight = PIECES, rate = price per piece) from the invoice's lines. */
    protected function insertLines(int $orderId, array $lines, float $gstPct): int
    {
        $batch = [];
        foreach ($lines as $l) {
            $pieces = $this->pieces($l);
            if (! $l->catalog_product_id || $pieces <= 0) {
                continue;
            }
            $total = (float) $l->line_total > 0 ? (float) $l->line_total : (float) $l->amount;
            $batch[] = [
                'order_request_id' => $orderId, 'catalog_product_id' => $l->catalog_product_id, 'material' => $l->material,
                'description' => mb_substr((string) $l->description, 0, 200), 'weight' => round($pieces, 4),
                'rate' => (float) $l->rate > 0 ? round((float) $l->rate, 2) : round($total / $pieces, 2),
                'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0,
                'gst_pct' => round($gstPct ?: (float) $l->gst_pct, 2), 'line_total' => round($total, 2),
            ];
        }
        if ($batch) {
            DB::table('branch_order_lines')->insert($batch);
        }

        return count($batch);
    }
}
