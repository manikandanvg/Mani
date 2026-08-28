<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchOrderEvent;
use App\Models\BranchOrderLine;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Push\Notifier;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customized-order travel (board 2026-08-27).
 *
 *   Taluka places the order → its supplier (District) may FORWARD it up the ladder →
 *   … → HQ ACCEPTS (delivery date, coin pick-up date, optional extra quote) and makes the
 *   pieces into its own stock → DELIVERY / FORWARD moves the pieces back DOWN the same
 *   road, hop by hop (every seller earns transfer margin) → the Taluka BILLS each piece
 *   as a G10 material sale at the frozen price (HQ's extra quote is debited from its
 *   branch wallet then) → HQ marks the customer's coins CAPTURED once its staff collect
 *   them (coins move Taluka → HQ directly).
 *
 * Custom pieces sit in `stock` under two system catalog items (Custom Order — Gold /
 * Silver) keyed by order line, labelled "Gold 15 g necklace (ORD-000123)".
 */
class CustomizeOrderService
{
    /** System catalog item that carries custom pieces of a metal (created on first use). */
    public static function customProduct(string $material): CatalogProduct
    {
        $material = $material === 'silver' ? 'silver' : 'gold';
        $code = $material === 'silver' ? 'CUSTOM-AG' : 'CUSTOM-AU';

        return CatalogProduct::withTrashed()->firstOrCreate(['code' => $code], [
            'name' => ['en' => 'Custom Order — ' . ucfirst($material)],
            'material' => $material,
            'default_weight' => 0,          // per-piece grams live on the order line, not the item
            'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0,
            'gst_pct' => 3, 'is_active' => true, 'is_custom_order' => true,
        ]);
    }

    public static function hqBranchId(): int
    {
        return (int) (Branch::where('level', 'hq')->value('id') ?? SalesService::HQ_BRANCH_ID);
    }

    /** The branch a fresh customized order is addressed to: the requestor's supplier, else HQ. */
    public function firstStop(Branch $requestor): int
    {
        return (int) ($requestor->source_branch_id ?: static::hqBranchId());
    }

    // ── Road map ─────────────────────────────────────────────────────

    public function log(BranchOrderRequest $order, string $action, ?int $branchId, ?int $toBranchId = null, ?string $note = null, array $meta = [], ?int $userId = null): BranchOrderEvent
    {
        return BranchOrderEvent::create([
            'order_request_id' => $order->id,
            'action' => $action,
            'branch_id' => $branchId,
            'to_branch_id' => $toBranchId,
            'user_id' => $userId ?? auth()->id(),
            'note' => $note ? mb_substr($note, 0, 500) : null,
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * Branch ids from the requestor up to the last stop the order reached (HQ once
     * accepted): [requestor, first supplier, …]. Delivery walks this list backwards.
     */
    public function travelPath(BranchOrderRequest $order): array
    {
        $path = [(int) $order->branch_id];
        $order->events()->whereIn('action', [BranchOrderEvent::SUBMITTED, BranchOrderEvent::FORWARDED])
            ->orderBy('id')->get()
            ->each(function (BranchOrderEvent $e) use (&$path) {
                if ($e->to_branch_id && (int) $e->to_branch_id !== end($path)) {
                    $path[] = (int) $e->to_branch_id;
                }
            });

        return $path;
    }

    /** Next branch DOWN the road from $holder (null when $holder is the requestor). */
    public function nextHopDown(BranchOrderRequest $order, int $holder): ?int
    {
        $path = $this->travelPath($order);
        $i = array_search($holder, $path, true);

        return $i === false || $i === 0 ? null : $path[$i - 1];
    }

    // ── Up the ladder ────────────────────────────────────────────────

    /** The branch currently holding the order passes it to ITS supplier (HQ when none). */
    public function forward(BranchOrderRequest $order, ?User $user = null, ?string $note = null): BranchOrderRequest
    {
        $user ??= auth()->user();

        return DB::transaction(function () use ($order, $user, $note) {
            $order = BranchOrderRequest::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->isCustomize(), 422, 'Only customized orders travel up the chain.');
            abort_unless($order->status === 'pending', 422, 'Only pending orders can be forwarded.');
            $this->assertActsFor($user, (int) $order->current_branch_id);

            $here = Branch::findOrFail($order->current_branch_id);
            abort_if($here->level === 'hq', 422, 'Head Office is the end of the road — accept or reject the order.');
            $next = (int) ($here->source_branch_id ?: static::hqBranchId());
            abort_if($next === $here->id, 422, 'This branch has no supplier to forward to.');

            $order->update(['current_branch_id' => $next]);
            $this->log($order, BranchOrderEvent::FORWARDED, $here->id, $next, $note, [], $user?->id);

            $this->notifyBranch($next, 'Customize order ' . $order->request_no . ' forwarded to you',
                $here->name . ' forwarded a customised order of ₹' . Money::group((float) $order->grand_total) . ' for ' . $order->customerName() . '.', $order);

            return $order->fresh();
        });
    }

    /** Any branch holding the order, or HQ, may reject it; the Taluka's payment comes back. */
    public function reject(BranchOrderRequest $order, ?User $user = null, ?string $note = null): void
    {
        $user ??= auth()->user();
        abort_unless($order->isCustomize(), 422, 'Use the normal reject for stock orders.');
        abort_unless($order->status === 'pending', 422, 'Only pending orders can be rejected.');
        if (! $this->isHq($user)) {
            $this->assertActsFor($user, (int) $order->current_branch_id);
        }

        DB::transaction(function () use ($order, $user, $note) {
            app(BranchOrderService::class)->reject($order, $user?->id);   // refunds cash stock + wallet, cancels coins
            $this->log($order, BranchOrderEvent::REJECTED, $this->isHq($user) ? static::hqBranchId() : (int) $order->current_branch_id, null, $note, [], $user?->id);
        });
    }

    // ── HQ ───────────────────────────────────────────────────────────

    /**
     * HQ accepts: delivery date, coin pick-up date, optional extra quote (debited from the
     * requestor's branch wallet when it bills the customer). The pieces are made into
     * HQ's own stock, one row per order line, ready to travel down.
     */
    public function accept(BranchOrderRequest $order, array $data, ?User $user = null): BranchOrderRequest
    {
        $user ??= auth()->user();
        abort_unless($this->isHq($user), 403, 'Only Head Office accepts customized orders.');

        return DB::transaction(function () use ($order, $data, $user) {
            $order = BranchOrderRequest::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->isCustomize(), 422, 'Only customized orders are accepted here.');
            abort_unless($order->status === 'pending', 422, 'Only pending orders can be accepted.');
            $hq = static::hqBranchId();
            abort_unless((int) $order->current_branch_id === $hq, 422,
                'This order has not reached Head Office yet — it is with ' . ($order->currentBranch?->name ?? 'a supplier') . '.');

            $extra = round(max(0.0, (float) ($data['quote_extra'] ?? 0)), 2);
            $order->update([
                'status' => 'approved',
                'approved_by' => $user?->id,
                'approved_at' => Carbon::now(),
                'quote_extra' => $extra,
                'delivery_date' => ! empty($data['delivery_date']) ? Carbon::parse($data['delivery_date'])->toDateString() : null,
                'coin_pickup_on' => ! empty($data['coin_pickup_on']) ? Carbon::parse($data['coin_pickup_on']) : null,
                'current_branch_id' => $hq,
            ]);

            // Make the pieces: one stock row per line at HQ.
            $today = Carbon::now()->toDateString();
            foreach ($order->lines as $line) {
                $cp = static::customProduct($line->material);
                $stock = Stock::firstOrCreate(
                    ['branch_id' => $hq, 'catalog_product_id' => $cp->id, 'order_line_id' => $line->id],
                    ['quantity' => 0, 'label' => static::pieceLabel($order, $line), 'last_rate' => $line->unit_price],
                );
                $stock->update(['quantity' => 1, 'label' => static::pieceLabel($order, $line)]);
                StockMovement::create([
                    'branch_id' => $hq, 'catalog_product_id' => $cp->id, 'type' => 'purchase', 'qty_change' => 1,
                    'balance_after' => 1, 'ref_type' => 'branch_order', 'ref_id' => $order->id,
                    'note' => 'Custom piece made — ' . static::pieceLabel($order, $line), 'moved_on' => $today, 'created_by' => $user?->id,
                ]);
            }

            $this->log($order, BranchOrderEvent::ACCEPTED, $hq, null, $data['note'] ?? null, [
                'quote_extra' => $extra, 'delivery_date' => $order->delivery_date?->toDateString(),
                'coin_pickup_on' => $order->coin_pickup_on?->format('Y-m-d H:i'),
            ], $user?->id);

            $this->notifyBranch((int) $order->branch_id, 'Customize order ' . $order->request_no . ' accepted',
                'Head Office accepted your customised order' . ($extra > 0 ? ' with an extra quote of ₹' . Money::group($extra) . ' (debited from your branch wallet when you bill the customer)' : '')
                . ($order->delivery_date ? '. Delivery ' . $order->delivery_date->format('d M Y') : '')
                . ($order->coin_pickup_on ? '; coin pick-up ' . $order->coin_pickup_on->format('d M Y H:i') : '') . '.', $order);

            return $order->fresh(['lines']);
        });
    }

    /**
     * The branch holding the pieces sends them one hop DOWN the road (HQ → … → requestor).
     * Every hop is a stock transfer: the sender earns its level's margin on grams × the
     * frozen price per gram. Reaching the requestor marks the order delivered.
     */
    public function deliver(BranchOrderRequest $order, ?User $user = null, ?string $note = null, bool $pulled = false): BranchOrderRequest
    {
        $user ??= auth()->user();

        return DB::transaction(function () use ($order, $user, $note, $pulled) {
            $order = BranchOrderRequest::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->isCustomize(), 422, 'Only customized orders travel this way.');
            abort_unless(in_array($order->status, ['approved', 'in_transit'], true), 422, 'The order must be accepted by Head Office before delivery.');
            $holder = (int) $order->current_branch_id;
            if (! $pulled) {
                $this->assertActsFor($user, $holder);
            }
            $next = $this->nextHopDown($order, $holder);
            abort_if($next === null, 422, 'The pieces are already with the ordering branch.');

            $today = Carbon::now()->toDateString();
            $from = Branch::findOrFail($holder);
            foreach ($order->lines as $line) {
                $cp = static::customProduct($line->material);
                $row = Stock::where('branch_id', $holder)->where('catalog_product_id', $cp->id)
                    ->where('order_line_id', $line->id)->lockForUpdate()->first();
                abort_if(! $row || (float) $row->quantity < 1, 422, 'Piece "' . static::pieceLabel($order, $line) . '" is not in ' . $from->name . "'s stock.");

                $row->update(['quantity' => 0]);
                StockMovement::create([
                    'branch_id' => $holder, 'catalog_product_id' => $cp->id, 'type' => 'transfer', 'qty_change' => -1,
                    'balance_after' => 0, 'ref_type' => 'branch_order', 'ref_id' => $order->id,
                    'note' => 'Custom piece sent down — ' . static::pieceLabel($order, $line), 'moved_on' => $today, 'created_by' => $user?->id,
                ]);
                $in = Stock::firstOrCreate(
                    ['branch_id' => $next, 'catalog_product_id' => $cp->id, 'order_line_id' => $line->id],
                    ['quantity' => 0, 'label' => static::pieceLabel($order, $line), 'last_rate' => $line->unit_price],
                );
                $in->update(['quantity' => 1, 'label' => static::pieceLabel($order, $line)]);
                StockMovement::create([
                    'branch_id' => $next, 'catalog_product_id' => $cp->id, 'type' => 'transfer', 'qty_change' => 1,
                    'balance_after' => 1, 'ref_type' => 'branch_order', 'ref_id' => $order->id,
                    'note' => 'Custom piece received — ' . static::pieceLabel($order, $line), 'moved_on' => $today, 'created_by' => $user?->id,
                ]);

                // Transfer margin for the sender at the FROZEN price per gram (its rate card
                // row for the Custom Order item decides the %; HQ earns none).
                app(StockTransferService::class)->record([
                    'source_branch_id' => $holder,
                    'destination_branch_id' => $next,
                    'catalog_product_id' => $cp->id,
                    'weight' => (float) $line->weight,
                    'quantity' => 0,
                    'unit_rate' => (float) $line->unit_price,
                    'transfer_date' => $today,
                    'created_by' => $user?->id,
                ]);
            }

            $arrived = $next === (int) $order->branch_id;
            $order->update([
                'current_branch_id' => $next,
                'status' => $arrived ? 'delivered' : 'in_transit',
                'delivered_at' => $arrived ? Carbon::now() : null,
            ]);
            $this->log($order, BranchOrderEvent::DELIVERED, $holder, $next, $note, [], $user?->id);

            $this->notifyBranch($next, 'Customize order ' . $order->request_no . ($arrived ? ' delivered' : ' pieces received'),
                $from->name . ' sent the pieces for ' . $order->customerName() . ($arrived ? ' — bill them to the customer from Sales.' : ' — forward them to the next branch.'), $order);

            return $order->fresh();
        });
    }

    /**
     * "Receive" (user 2026-08-29): the ORDERING branch (or HQ on its behalf) pulls the pieces
     * the rest of the way down — every remaining hop is delivered in turn, each sender still
     * earning its transfer margin, so an intermediate dealer who never logs in cannot strand
     * the order. Ends with status delivered.
     */
    public function receive(BranchOrderRequest $order, ?User $user = null, ?string $note = null): BranchOrderRequest
    {
        $user ??= auth()->user();
        abort_unless($user, 403);
        abort_unless($order->isCustomize(), 422, 'Only customized orders travel this way.');
        abort_unless(in_array($order->status, ['approved', 'in_transit'], true), 422, 'Nothing to receive — the order is ' . $order->status . '.');
        if ($user->isDistributor()) {
            abort_unless((int) $user->branch_id === (int) $order->branch_id, 403, 'Only the ordering branch can receive this order.');
        }

        return DB::transaction(function () use ($order, $user, $note) {
            $hops = 0;
            while (in_array($order->status, ['approved', 'in_transit'], true) && $hops < 12) {
                $order = $this->deliver($order, $user, $note ?: 'Received by the ordering branch (pulled through)', pulled: true);
                $hops++;
            }

            return $order;
        });
    }

    /** HQ staff received the customer's coins from the Taluka: coins move Taluka → HQ. */
    public function captureCoins(BranchOrderRequest $order, ?User $user = null, ?string $note = null): BranchOrderRequest
    {
        $user ??= auth()->user();
        abort_unless($this->isHq($user), 403, 'Only Head Office captures coins.');

        return DB::transaction(function () use ($order, $user, $note) {
            $order = BranchOrderRequest::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $sr = $order->salesReturn;
            abort_if(! $sr, 422, 'No coins were applied to this order.');
            if ($order->coin_captured_at) {
                abort(422, 'Coins were already captured on ' . $order->coin_captured_at->format('d M Y H:i') . '.');
            }
            abort_if(in_array($order->status, ['rejected', 'cancelled'], true), 422, 'The order was ' . $order->status . '.');
            abort_if($sr->status === SalesReturn::STATUS_CANCELLED, 422, 'The coin return was cancelled.');

            $svc = app(SalesReturnService::class);
            if ($sr->status === SalesReturn::STATUS_PENDING) {
                $svc->markCollected($sr, $user?->id);   // handed over = collected
            }
            $sr->refresh();
            if ($sr->status === SalesReturn::STATUS_COLLECTED) {
                $svc->relay($sr, Branch::find(static::hqBranchId()), $user?->id);   // Taluka → HQ
            }

            $order->update(['coin_captured_at' => Carbon::now()]);
            $this->log($order, BranchOrderEvent::COINS_CAPTURED, static::hqBranchId(), null, $note,
                ['coins' => (float) $sr->quantity, 'grams' => (float) $sr->grams, 'value' => (float) $sr->credit_value], $user?->id);

            return $order->fresh();
        });
    }

    // ── Billing (G10 at the frozen price) ────────────────────────────

    /**
     * Delivered, not-yet-billed pieces at a branch, grouped per order + material — each
     * group is one G10 invoice (P206 gold / P211 silver).
     *
     * @return array<string, array{order:BranchOrderRequest, material:string, lines:\Illuminate\Support\Collection}>
     */
    public function billableGroups(int $branchId): array
    {
        $out = [];
        BranchOrderRequest::with(['lines', 'member'])
            ->where('source', BranchOrderService::SOURCE_CUSTOMIZE)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['delivered', 'billed'])
            ->orderByDesc('id')->get()
            ->each(function (BranchOrderRequest $o) use (&$out) {
                foreach ($o->lines->whereNull('billed_at')->groupBy('material') as $material => $lines) {
                    $out[$o->id . ':' . $material] = ['order' => $o, 'material' => $material, 'lines' => $lines->values()];
                }
            });

        return $out;
    }

    /** Sales-cart lines for one billable group — frozen order prices, locked on the page. */
    public function cartFor(BranchOrderRequest $order, string $material): array
    {
        $cp = static::customProduct($material);

        return $order->lines->where('material', $material)->whereNull('billed_at')->values()
            ->map(fn (BranchOrderLine $l) => [
                'order_line_id' => $l->id,
                'catalog_product_id' => $cp->id,
                'material' => $material,
                'description' => static::pieceLabel($order, $l),
                'qty' => 1,
                'unit_weight' => (float) $l->weight,
                'weight' => (float) $l->weight,
                'rate' => (float) $l->unit_price,
                'purity' => null,
                'making_charge_pct' => (float) $l->making_charge_pct,
                'wastage_charge_pct' => (float) $l->wastage_charge_pct,
                'hallmark_charge' => 0,
                'gst_pct' => (float) $l->gst_pct,
            ])->all();
    }

    /**
     * Called by SalesService inside the invoice transaction: marks the pieces billed,
     * debits HQ's extra quote from the branch wallet (once, on the first bill) and closes
     * the order when every piece is billed.
     */
    public function onBilled(BranchOrderRequest $order, array $lineIds, SalesInvoice $invoice, ?int $userId): void
    {
        $order = BranchOrderRequest::whereKey($order->id)->lockForUpdate()->firstOrFail();
        abort_unless(in_array($order->status, ['delivered', 'billed'], true), 422, 'The pieces have not been delivered to this branch yet.');
        abort_unless((int) $order->branch_id === (int) $invoice->branch_id, 422, 'Only the ordering branch may bill a customized order.');

        $lines = $order->lines()->whereIn('id', $lineIds)->whereNull('billed_at')->get();
        abort_if($lines->count() !== count($lineIds), 422, 'Some pieces on this bill were already billed or do not belong to the order.');

        if ((float) $order->quote_extra > 0 && ! $order->quote_debited_at) {
            $branch = Branch::whereKey($order->branch_id)->lockForUpdate()->first();
            abort_if(! $branch || (float) $branch->digi_cash_balance + 0.01 < (float) $order->quote_extra, 422,
                "Head Office's extra quote of ₹" . Money::group((float) $order->quote_extra) . ' is debited from your branch wallet at billing, but it holds only ₹'
                . Money::group((float) ($branch->digi_cash_balance ?? 0)) . '. Top up the wallet first.');
            $branch->decrement('digi_cash_balance', (float) $order->quote_extra);
            $order->quote_debited_at = Carbon::now();
        }

        $lines->each(fn (BranchOrderLine $l) => $l->update(['sales_invoice_id' => $invoice->id, 'billed_at' => Carbon::now()]));
        $allBilled = $order->lines()->whereNull('billed_at')->doesntExist();
        $order->status = 'billed';   // partially billed orders stay billable via billableGroups()
        if (! $allBilled) {
            $order->status = 'delivered';
        }
        $order->save();

        $this->log($order, BranchOrderEvent::BILLED, (int) $order->branch_id, null, 'Invoice ' . $invoice->invoice_no,
            ['invoice_no' => $invoice->invoice_no, 'lines' => $lineIds, 'quote_debited' => $order->quote_debited_at?->toDateTimeString()], $userId);
    }

    // ── helpers ──────────────────────────────────────────────────────

    public static function pieceLabel(BranchOrderRequest $order, BranchOrderLine $line): string
    {
        return ucfirst((string) $line->material) . ' ' . rtrim(rtrim(number_format((float) $line->weight, 3), '0'), '.') . ' g '
            . ($line->description ?: 'custom piece') . ' (' . $order->request_no . ')';
    }

    public function isHq(?User $user): bool
    {
        return $user !== null && ! $user->isDistributor();
    }

    /** A distributor may act only for the branch the order currently sits with; HQ for HQ. */
    protected function assertActsFor(?User $user, int $branchId): void
    {
        abort_unless($user, 403);
        if ($user->isDistributor()) {
            abort_unless((int) $user->branch_id === $branchId, 403, 'This order is not with your branch right now.');
        } else {
            abort_unless($branchId === static::hqBranchId(), 403, 'This order is currently with ' . (Branch::find($branchId)?->name ?? 'another branch') . '.');
        }
    }

    protected function notifyBranch(int $branchId, string $title, string $body, BranchOrderRequest $order): void
    {
        $branch = Branch::find($branchId);
        if (! $branch) {
            return;
        }
        if ($branch->level === 'hq' || $branchId === static::hqBranchId()) {
            Notifier::admins($title, $body, url: '/admin/branch-orders', category: 'order');

            return;
        }
        Notifier::to($branch->distributorUser?->memberAccount, 'order', $title, $body, route: '/stock-orders/' . $order->id);
    }
}
