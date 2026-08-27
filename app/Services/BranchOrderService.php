<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchOrderLine;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\RedemptionInvoice;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockTransferService;
use App\Support\CustomizeOrderPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Distributor "Order Form" → HQ approval → stock transfer (legacy generatebranchorderrequest
 * + approveOrderRequest). Pricing is recomputed server-side from the catalog product and the
 * live rate — the cart is never trusted for money.
 */
class BranchOrderService
{
    /** Upper bound for a single line's weight/value — fits decimal(15,4) used across stock tables. */
    public const MAX_LINE_QTY = 9_999_999_999;

    /** Where a request originated. Order-Form is the normal path; Redemption is a restock. */
    public const SOURCE_ORDER_FORM = 'order_form';

    public const SOURCE_REDEMPTION = 'redemption';

    /** Bespoke gold/silver pieces built on the Customize Order Form (board 2026-08-26). */
    public const SOURCE_CUSTOMIZE = 'customize';


    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            self::SOURCE_REDEMPTION => 'Redemption',
            self::SOURCE_CUSTOMIZE => 'Customize order',
            default => 'Order form',
        };
    }

    /**
     * Raise a restock order from a redemption invoice: the branch redeemed (gave out) stock,
     * so it requests the same items back from its supplier (HQ). The request enters the normal
     * pending queue and, once approved, replenishes the branch's stock like any other order.
     *
     * Unlike the Order Form this is NOT subject to the order limit or the ordering window —
     * it only restores stock the branch already parted with. Idempotent per invoice.
     */
    /**
     * Price the items a redemption restock would order — without persisting anything. Used by
     * the confirmation preview and by createFromRedemption (same pricing, single source of truth).
     *
     * @return array{lines: array<int, array{code:?string, description:string, material:?string, weight:float, line_total:float}>, cross: float, gst: float, grand: float, count: int}
     */
    public function previewFromRedemption(RedemptionInvoice $invoice): array
    {
        [$priced, $cross, $gst] = $this->priceRedemptionLines($invoice);

        return [
            'lines' => array_map(fn ($r) => [
                'code' => $r['cp']->code,
                'description' => $r['description'] ?: strtoupper((string) $r['cp']->material),
                'material' => $r['cp']->material,
                'weight' => $r['weight'],
                'line_total' => $r['p']['line_total'],
            ], $priced),
            'cross' => round($cross, 2),
            'gst' => round($gst, 2),
            'grand' => round($cross + $gst, 2),
            'count' => count($priced),
        ];
    }

    /** Build + price the restock lines from a redemption invoice. @return array{0: array, 1: float, 2: float} */
    protected function priceRedemptionLines(RedemptionInvoice $invoice): array
    {
        $invoice->loadMissing('lines');
        $rate = LiveRate::latestFor('IN') ?? LiveRate::query()->latest('id')->first();

        $priced = [];
        $cross = 0.0;
        $gst = 0.0;
        foreach ($invoice->lines as $line) {
            $cp = $line->catalog_product_id ? CatalogProduct::find($line->catalog_product_id) : null;
            if (! $cp) {
                continue;
            }
            // Quantity to restock, taken from the redemption line as-is: pieces for vessels,
            // else grams (qty × unit weight) — falling back to the line quantity when the
            // unit weight is not recorded (e.g. Mode-A lines rebuilt from the original sale).
            $qtyWeight = (float) $line->quantity * (float) $line->unit_weight;
            $weight = $cp->material === 'vessel'
                ? (float) $line->quantity
                : ($qtyWeight > 0 ? $qtyWeight : (float) $line->quantity);
            if ($weight <= 0) {
                continue;
            }
            $p = $this->priceLine($cp, $weight, $rate);
            $priced[] = ['cp' => $cp, 'weight' => $weight, 'description' => $line->description, 'p' => $p];
            $cross += $p['subtotal'];
            $gst += $p['gst'];
        }

        return [$priced, $cross, $gst];
    }

    public function createFromRedemption(RedemptionInvoice $invoice, ?int $userId = null): BranchOrderRequest
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $existing = BranchOrderRequest::where('source', self::SOURCE_REDEMPTION)
                ->where('source_ref', $invoice->id)->first();
            abort_if($existing !== null, 422, 'A restock order (' . $existing?->request_no . ') was already raised for this redemption.');

            [$priced, $cross, $gst] = $this->priceRedemptionLines($invoice);
            abort_if(empty($priced), 422, 'This redemption has no stock items to restock.');

            $request = BranchOrderRequest::create([
                'request_no' => $this->nextRequestNo(),
                'source' => self::SOURCE_REDEMPTION,
                'source_ref' => $invoice->id,
                'branch_id' => $invoice->branch_id,
                'requested_by' => $userId ?? auth()->id(),
                'no_of_items' => count($priced),
                'cross_total' => round($cross, 2),
                'gst_total' => round($gst, 2),
                'grand_total' => round($cross + $gst, 2),
                'payment_type' => 'cash',
                'payment_remarks' => 'Restock for redemption invoice ' . $invoice->invoice_no,
                'status' => 'pending',
            ]);

            foreach ($priced as $row) {
                BranchOrderLine::create([
                    'order_request_id' => $request->id,
                    'catalog_product_id' => $row['cp']->id,
                    'material' => $row['cp']->material,
                    'description' => $row['description'],
                    'weight' => $row['weight'],
                    'rate' => $row['p']['rate'],
                    'making_charge_pct' => $row['p']['making_charge_pct'],
                    'wastage_charge_pct' => $row['p']['wastage_charge_pct'],
                    'hallmark_charge' => $row['p']['hallmark_charge'],
                    'gst_pct' => $row['p']['gst_pct'],
                    'line_total' => $row['p']['line_total'],
                ]);
            }

            return $request->fresh('lines');
        });
    }

    /** Submit a distributor's order to HQ as a PENDING request. */
    public function submit(array $data): BranchOrderRequest
    {
        $request = DB::transaction(function () use ($data) {
            $branchId = (int) $data['branch_id'];
            $userId = $data['requested_by'] ?? auth()->id();
            $rate = LiveRate::latestFor('IN') ?? LiveRate::query()->latest('id')->first();

            $lines = collect($data['lines'] ?? [])
                ->filter(fn ($l) => ! empty($l['catalog_product_id']) && (float) ($l['weight'] ?? 0) > 0)
                ->values();
            abort_if($lines->isEmpty(), 422, 'Add at least one item to the order.');

            // Price every line first (server-side) so we can check the branch limit before persisting.
            $priced = [];
            $cross = 0.0;
            $gst = 0.0;
            foreach ($lines as $l) {
                $cp = CatalogProduct::find($l['catalog_product_id']);
                if (! $cp) {
                    continue;
                }
                $weight = (float) $l['weight'];
                abort_if($weight > self::MAX_LINE_QTY, 422, 'Quantity / amount on a line is too large.');
                $p = $this->priceLine($cp, $weight, $rate);
                $priced[] = ['cp' => $cp, 'weight' => $weight, 'description' => $l['description'] ?? null, 'p' => $p];
                $cross += $p['subtotal'];
                $gst += $p['gst'];
            }
            abort_if(empty($priced), 422, 'Add at least one item to the order.');
            $grand = round($cross + $gst, 2);

            $this->assertWithinOrderLimit($userId ? User::find($userId) : null, $branchId, $grand);

            // Digi cash payment: the wallet (credited by approved stock returns) is
            // charged at submission; a later rejection refunds it.
            if (($data['payment_type'] ?? null) === 'digi_cash') {
                $branch = Branch::whereKey($branchId)->lockForUpdate()->first();
                abort_if(! $branch || (float) $branch->digi_cash_balance + 0.01 < $grand, 422,
                    'Insufficient Digi cash: wallet holds ₹' . \App\Support\Money::group((float) ($branch->digi_cash_balance ?? 0))
                    . ', order needs ₹' . \App\Support\Money::group($grand) . '.');
                $branch->decrement('digi_cash_balance', $grand);
            }

            $request = BranchOrderRequest::create([
                'request_no' => $this->nextRequestNo(),
                'branch_id' => $branchId,
                'requested_by' => $userId,
                'no_of_items' => count($priced),
                'cross_total' => round($cross, 2),
                'gst_total' => round($gst, 2),
                'grand_total' => $grand,
                'payment_type' => $data['payment_type'] ?? 'cash',
                'payment_remarks' => $data['payment_remarks'] ?? null,
                'status' => 'pending',
            ]);

            foreach ($priced as $row) {
                BranchOrderLine::create([
                    'order_request_id' => $request->id,
                    'catalog_product_id' => $row['cp']->id,
                    'material' => $row['cp']->material,
                    'description' => $row['description'],
                    'weight' => $row['weight'],
                    'rate' => $row['p']['rate'],
                    'making_charge_pct' => $row['p']['making_charge_pct'],
                    'wastage_charge_pct' => $row['p']['wastage_charge_pct'],
                    'hallmark_charge' => $row['p']['hallmark_charge'],
                    'gst_pct' => $row['p']['gst_pct'],
                    'line_total' => $row['p']['line_total'],
                ]);
            }

            $this->recordAttachments($request, $data, $userId);

            return $request->fresh('lines');
        });

        // HQ bell: a dealer order awaits approval (board 2026-08-11).
        \App\Services\Push\Notifier::admins(
            'Stock order ' . $request->request_no . ' awaiting approval',
            ($request->branch?->name ?? 'A branch') . ' ordered ₹' . \App\Support\Money::group((float) $request->grand_total)
                . ' (' . $request->no_of_items . ' item(s), ' . strtoupper((string) $request->payment_type) . ').',
            url: '/admin/branch-orders',
            category: 'order',
        );

        return $request;
    }

    /**
     * Submit a CUSTOMISED order (board 2026-08-26, corrected the same day): bespoke
     * gold/silver pieces described by the distributor. Price per gram = live rate + HQ
     * margin; making + wastage % come from the CHARGE BRACKET slab the weight falls in;
     * GST on top; net = the lot. The customer is either an EXISTING member (whose RD
     * 100 mg coins may be applied — a sales return is raised and its live metal value
     * becomes `coin_credit`) or a NEW customer whose details are kept on the order only
     * (never written to members). Payment is SPLIT: an amount from the branch's cash
     * stock + an amount from the branch wallet; with the coin credit they must cover the
     * total IN FULL — only then does the order proceed to the supplier (pending). Both
     * balances are charged now and refunded on rejection.
     *
     * @param array $data keys: branch_id, requested_by?, lines[[material, description, grams]],
     *                    customer_mode ('existing'|'new'), member_id?, customer{name,phone,email,address,city,pincode}?,
     *                    coin_qty?, coin_product_id?, coin_collect_on?, pay_cash?, pay_wallet?,
     *                    payment_remarks?, attachments?, attachment_names?
     */
    public function submitCustomize(array $data): BranchOrderRequest
    {
        $request = DB::transaction(function () use ($data) {
            $branchId = (int) $data['branch_id'];
            $userId = $data['requested_by'] ?? auth()->id();
            $rate = LiveRate::latestFor('IN') ?? LiveRate::query()->latest('id')->first();

            $lines = collect($data['lines'] ?? [])
                ->filter(fn ($l) => (float) ($l['grams'] ?? 0) > 0)
                ->values();
            abort_if($lines->isEmpty(), 422, 'Add at least one item to the order.');

            $priced = [];
            $cross = 0.0;
            $gst = 0.0;
            foreach ($lines as $l) {
                $material = ($l['material'] ?? 'gold') === 'silver' ? 'silver' : 'gold';
                $grams = (float) $l['grams'];
                abort_if($grams > self::MAX_LINE_QTY, 422, 'Quantity on a line is too large.');
                abort_if(CustomizeOrderPricing::liveRate($material, $rate) <= 0, 422,
                    'No live ' . $material . ' rate configured — set it before ordering.');
                $p = CustomizeOrderPricing::priceLine($material, $grams, $rate);
                $priced[] = [
                    'material' => $material,
                    'grams' => $grams,
                    'description' => trim((string) ($l['description'] ?? '')) ?: ucfirst($material) . ' item',
                    'p' => $p,
                ];
                $cross += $p['net'];
                $gst += $p['gst'];
            }
            $grand = round($cross + $gst, 2);

            // Customer: existing member, or a new customer captured on the order only.
            $memberId = null;
            $customer = null;
            if (($data['customer_mode'] ?? 'existing') === 'new') {
                $c = (array) ($data['customer'] ?? []);
                $customer = [];
                foreach (['name', 'phone', 'email', 'address', 'city', 'pincode'] as $k) {
                    $v = trim((string) ($c[$k] ?? ''));
                    if ($v !== '') {
                        $customer[$k] = mb_substr($v, 0, 200);
                    }
                }
                abort_if(empty($customer['name']) || empty($customer['phone']), 422, "Enter the new customer's name and phone.");
            } else {
                $memberId = ! empty($data['member_id']) ? (int) $data['member_id'] : null;
                abort_if(! $memberId || ! Member::whereKey($memberId)->exists(), 422,
                    'Select the existing customer (distributor) this order is for, or switch to "New customer".');
            }

            // Customer coins applied as payment → sales return (collected later, relayed on approval).
            $salesReturn = null;
            $coinCredit = 0.0;
            $coinQty = (float) ($data['coin_qty'] ?? 0);
            abort_if($coinQty > 0 && ! $memberId, 422, 'Coins can be applied only for an existing customer.');
            if ($coinQty > 0) {
                $salesReturn = app(SalesReturnService::class)->create([
                    'branch_id' => $branchId,
                    'member_id' => $memberId,
                    'catalog_product_id' => $data['coin_product_id'] ?? null,
                    'quantity' => $coinQty,
                    'collect_on' => $data['coin_collect_on'] ?? null,
                    'notes' => 'Applied to a customised order',
                    'created_by' => $userId,
                ]);
                $coinCredit = (float) $salesReturn->credit_value;
                abort_if($coinCredit > $grand + 0.004, 422, sprintf(
                    'Coin value (₹%s) exceeds the order total (₹%s) — reduce the number of coins.',
                    \App\Support\Money::group($coinCredit), \App\Support\Money::group($grand)
                ));
            }
            $due = round($grand - $coinCredit, 2);

            // Split payment — the order proceeds only when it is paid IN FULL.
            $payCash = round(max(0.0, (float) ($data['pay_cash'] ?? 0)), 2);
            $payWallet = round(max(0.0, (float) ($data['pay_wallet'] ?? 0)), 2);
            $paid = round($payCash + $payWallet, 2);
            abort_if(abs($paid - $due) > 0.009, 422, sprintf(
                'Payment must cover the order in full: cash stock ₹%s + branch wallet ₹%s = ₹%s, but ₹%s is due (order ₹%s − coins ₹%s).',
                \App\Support\Money::group($payCash), \App\Support\Money::group($payWallet), \App\Support\Money::group($paid),
                \App\Support\Money::group($due), \App\Support\Money::group($grand), \App\Support\Money::group($coinCredit)
            ));

            $this->assertWithinOrderLimit($userId ? User::find($userId) : null, $branchId, $grand);

            $request = BranchOrderRequest::create([
                'request_no' => $this->nextRequestNo(),
                'source' => self::SOURCE_CUSTOMIZE,
                'branch_id' => $branchId,
                // Travel (board 2026-08-27): starts with the requestor's supplier (HQ when none).
                'current_branch_id' => app(CustomizeOrderService::class)->firstStop(Branch::findOrFail($branchId)),
                'member_id' => $memberId,
                'customer_details' => $customer,
                'requested_by' => $userId,
                'no_of_items' => count($priced),
                'cross_total' => round($cross, 2),
                'gst_total' => round($gst, 2),
                'grand_total' => $grand,
                'coin_credit' => round($coinCredit, 2),
                'pay_cash' => $payCash,
                'pay_wallet' => $payWallet,
                'paid_amount' => $paid,
                'sales_return_id' => $salesReturn?->id,
                'payment_type' => $payCash > 0 && $payWallet > 0 ? 'split' : ($payWallet > 0 ? 'digi_cash' : 'cash'),
                'payment_remarks' => $data['payment_remarks'] ?? null,
                'status' => 'pending',
            ]);

            // Charge the two balances now (refunded by reject()).
            if ($payCash > 0) {
                $this->debitCashStock($branchId, $payCash, $request->id, $userId);
            }
            if ($payWallet > 0) {
                $branch = Branch::whereKey($branchId)->lockForUpdate()->first();
                abort_if(! $branch || (float) $branch->digi_cash_balance + 0.01 < $payWallet, 422,
                    'Insufficient branch wallet: it holds ₹' . \App\Support\Money::group((float) ($branch->digi_cash_balance ?? 0))
                    . ', you entered ₹' . \App\Support\Money::group($payWallet) . '.');
                $branch->decrement('digi_cash_balance', $payWallet);
            }

            foreach ($priced as $row) {
                BranchOrderLine::create([
                    'order_request_id' => $request->id,
                    'catalog_product_id' => null,
                    'material' => $row['material'],
                    'description' => mb_substr($row['description'], 0, 200),
                    'weight' => $row['grams'],
                    'rate' => $row['p']['rate'],
                    'margin_per_g' => $row['p']['margin_per_g'],
                    'cost_per_g' => 0,
                    'unit_price' => $row['p']['unit_price'],
                    'making_charge_pct' => $row['p']['making_pct'],
                    'wastage_charge_pct' => $row['p']['wastage_pct'],
                    'hallmark_charge' => 0,
                    'gst_pct' => $row['p']['gst_pct'],
                    'line_total' => $row['p']['line_total'],
                ]);
            }

            $this->recordAttachments($request, $data, $userId);
            app(CustomizeOrderService::class)->log($request, \App\Models\BranchOrderEvent::SUBMITTED, $branchId, (int) $request->current_branch_id, null, [], $userId);

            return $request->fresh('lines');
        });

        $seller = $request->branch?->sourceBranch;
        $body = ($request->branch?->name ?? 'A branch') . ' sent a customised order of ₹'
            . \App\Support\Money::group((float) $request->grand_total)
            . ' for ' . $request->customerName()
            . ' (' . $request->no_of_items . ' item(s), ' . BranchOrderRequest::paymentLabel($request->payment_type)
            . ((float) $request->coin_credit > 0 ? ', coins ₹' . \App\Support\Money::group((float) $request->coin_credit) : '') . ').';
        \App\Services\Push\Notifier::admins(
            'Customize order ' . $request->request_no . ' awaiting approval', $body,
            url: '/admin/branch-orders', category: 'order',
        );
        // The supplier may itself be a dealer — tell that login too.
        if ($seller && $seller->level !== 'hq') {
            \App\Services\Push\Notifier::to(
                $seller->distributorUser?->memberAccount, 'order',
                'Customize order ' . $request->request_no . ' received', $body,
                route: '/stock-orders/' . $request->id,
            );
        }

        return $request;
    }

    /** Branch cash stock (₹ held as `cash` catalog items) — what a customised order can be paid from. */
    public static function cashStockBalance(int $branchId): float
    {
        return (float) Stock::where('branch_id', $branchId)
            ->whereHas('catalogProduct', fn ($q) => $q->where('material', 'cash'))
            ->sum('quantity');
    }

    /** Take a customised-order payment out of the branch's cash stock (largest pot first). */
    protected function debitCashStock(int $branchId, float $amount, int $orderId, ?int $userId): void
    {
        $rows = Stock::where('branch_id', $branchId)
            ->whereHas('catalogProduct', fn ($q) => $q->where('material', 'cash'))
            ->where('quantity', '>', 0)->lockForUpdate()->orderByDesc('quantity')->get();
        $held = (float) $rows->sum('quantity');
        abort_if($held + 0.01 < $amount, 422,
            'Insufficient cash stock: your branch holds ₹' . \App\Support\Money::group($held)
            . ', you entered ₹' . \App\Support\Money::group($amount) . '.');

        $left = $amount;
        foreach ($rows as $stock) {
            if ($left <= 0) {
                break;
            }
            $take = round(min($left, (float) $stock->quantity), 2);
            $stock->decrement('quantity', $take);
            StockMovement::create([
                'branch_id' => $branchId,
                'catalog_product_id' => $stock->catalog_product_id,
                'type' => 'adjustment',
                'qty_change' => -$take,
                'balance_after' => $stock->fresh()->quantity,
                'ref_type' => 'branch_order',
                'ref_id' => $orderId,
                'note' => 'Paid towards customised order from cash stock',
                'moved_on' => Carbon::now()->toDateString(),
                'created_by' => $userId,
            ]);
            $left = round($left - $take, 2);
        }
    }

    /** Put a rejected customised order's cash-stock payment back. */
    protected function creditCashStock(int $branchId, float $amount, int $orderId, ?int $userId): void
    {
        $stock = Stock::where('branch_id', $branchId)
            ->whereHas('catalogProduct', fn ($q) => $q->where('material', 'cash'))
            ->orderByDesc('quantity')->first();
        if (! $stock) {
            $cp = CatalogProduct::where('material', 'cash')->where('is_active', true)->orderBy('id')->first();
            if (! $cp) {
                return;   // no cash catalog item at all — nothing to credit back into
            }
            $stock = Stock::firstOrCreate(['branch_id' => $branchId, 'catalog_product_id' => $cp->id], ['quantity' => 0]);
        }
        $stock->increment('quantity', $amount);
        StockMovement::create([
            'branch_id' => $branchId,
            'catalog_product_id' => $stock->catalog_product_id,
            'type' => 'adjustment',
            'qty_change' => $amount,
            'balance_after' => $stock->fresh()->quantity,
            'ref_type' => 'branch_order',
            'ref_id' => $orderId,
            'note' => 'Refund — customised order rejected',
            'moved_on' => Carbon::now()->toDateString(),
            'created_by' => $userId,
        ]);
    }

    /**
     * Payment-proof files (board phase-1, 2026-08-21 / private disk 2026-08-26): already
     * stored by the form; record them against the order for the approver. Files land on
     * the private `local` disk now; the `public` disk is checked for older uploads.
     */
    protected function recordAttachments(BranchOrderRequest $request, array $data, ?int $userId): void
    {
        $names = (array) ($data['attachment_names'] ?? []);
        foreach ((array) ($data['attachments'] ?? []) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $diskName = 'local';
            $disk = \Illuminate\Support\Facades\Storage::disk($diskName);
            if (! $disk->exists($path)) {
                $diskName = 'public';
                $disk = \Illuminate\Support\Facades\Storage::disk($diskName);
            }
            $exists = $disk->exists($path);
            \App\Models\BranchOrderAttachment::create([
                'order_request_id' => $request->id,
                'path' => $path,
                'disk' => $diskName,
                'original_name' => $names[$path] ?? basename($path),
                'mime' => $exists ? $disk->mimeType($path) : null,
                'size' => $exists ? $disk->size($path) : null,
                'uploaded_by' => $userId,
            ]);
        }
    }

    /**
     * Enforce the distributor's order limit on outstanding (pending) orders. The limit is
     * max(member BV, invested) — legacy admin/Master.php. Admin / HQ staff (non-distributors)
     * are unlimited; a distributor with neither BV nor investment on record is treated as
     * "no limit configured" rather than locked out.
     */
    protected function assertWithinOrderLimit(?User $user, int $branchId, float $grand): void
    {
        if (! $user || ! $user->isDistributor()) {
            return;   // admin / HQ staff: unlimited
        }
        $limit = $user->orderLimit();   // max(member BV, invested)
        if ($limit <= 0) {
            return;
        }

        $pending = (float) BranchOrderRequest::where('branch_id', $branchId)
            ->where('status', 'pending')->sum('grand_total');

        abort_if(
            $pending + $grand > $limit + 0.004,
            422,
            sprintf(
                'Order exceeds your limit of ₹%s (₹%s already pending, this order ₹%s).',
                \App\Support\Money::group($limit),
                \App\Support\Money::group($pending),
                \App\Support\Money::group($grand)
            )
        );
    }

    /**
     * Approve a branch order = fulfil it. For every line the goods move from the SELLER
     * (the branch the order was placed with — the buyer's source) to the buyer: the
     * buyer's stock goes up, the seller's stock goes down, a stock_transfers row is logged
     * and the seller earns the stock-transfer commission (StockTransferService). Cash lines
     * carry no goods transfer. When no seller is configured (buyer sources direct from HQ)
     * the stock is simply added, as before.
     */
    public function approve(BranchOrderRequest $request, ?int $approverId = null): void
    {
        DB::transaction(function () use ($request, $approverId) {
            abort_unless($request->status === 'pending', 422, 'Only pending requests can be approved.');
            // Customized orders travel the ladder and are ACCEPTED by HQ (CustomizeOrderService).
            abort_if($request->source === self::SOURCE_CUSTOMIZE, 422,
                'Customized orders are forwarded up the chain and accepted by Head Office — use Forward / Accept.');
            $approverId = $approverId ?? auth()->id();

            $buyerId = $request->branch_id;
            $seller = $request->branch?->sourceBranch;   // the fulfilling branch (one hop up)
            $today = Carbon::now()->toDateString();

            foreach ($request->lines as $line) {
                if (! $line->catalog_product_id) {
                    continue;
                }
                $weight = (float) $line->weight;
                // Order lines are priced in grams (cash = ₹); STOCK moves in pieces.
                $pieces = CatalogProduct::find($line->catalog_product_id)?->piecesFromWeight($weight) ?? $weight;

                // Buyer receives the goods.
                $stock = Stock::firstOrCreate(
                    ['branch_id' => $buyerId, 'catalog_product_id' => $line->catalog_product_id],
                    ['quantity' => 0]
                );
                $stock->increment('quantity', $pieces);
                if ((float) $line->rate > 0) {
                    $stock->update(['last_rate' => $line->rate]);
                }
                StockMovement::create([
                    'branch_id' => $buyerId,
                    'catalog_product_id' => $line->catalog_product_id,
                    'type' => 'purchase',
                    'qty_change' => $pieces,
                    'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'branch_order',
                    'ref_id' => $request->id,
                    'moved_on' => $today,
                    'created_by' => $approverId,
                ]);

                // Seller (source branch) ships the goods + earns the stock-transfer commission.
                // Cash is money, not transferable goods — skip it.
                if ($seller && $line->material !== 'cash') {
                    $this->shipFromSeller($seller, $buyerId, $line, $weight, $pieces, $request->id, $today, $approverId);
                }
            }

            $request->update([
                'status' => 'approved',
                'approved_by' => $approverId,
                'approved_at' => Carbon::now(),
            ]);

            // A redemption-sourced order closes the loop: approving the restock marks the
            // originating redemption invoice as passed (pending → passed).
            if ($request->source === self::SOURCE_REDEMPTION && $request->source_ref) {
                RedemptionInvoice::whereKey($request->source_ref)->update(['payment_mode' => 'passed']);
            }
        });

        // Order-approved acknowledgement to the ordering dealer (board 2026-08-11).
        $customize = $request->source === self::SOURCE_CUSTOMIZE;
        \App\Services\Push\Notifier::to(
            Branch::find($request->branch_id)?->distributorUser?->memberAccount,
            'order',
            ($customize ? 'Customize order accepted — ' : 'Stock order approved — ') . $request->request_no,
            $customize
                ? 'Your supplier accepted your customised order of ₹' . \App\Support\Money::group((float) $request->grand_total) . '.'
                : 'Head Office approved your order of ₹' . \App\Support\Money::group((float) $request->grand_total)
                    . '. The stock has been added to your branch.',
            route: '/stock-orders/' . $request->id,
        );
    }

    /** Deduct the line from the seller's stock, log the move, and book the transfer + commission. */
    protected function shipFromSeller($seller, int $buyerId, BranchOrderLine $line, float $weight, float $pieces, int $orderId, string $today, ?int $approverId): void
    {
        $sellerStock = Stock::firstOrCreate(
            ['branch_id' => $seller->id, 'catalog_product_id' => $line->catalog_product_id],
            ['quantity' => 0]
        );
        // stock ledger moves PIECES; the money/transfer maths below stay gram-based
        $sellerStock->decrement('quantity', $pieces);
        StockMovement::create([
            'branch_id' => $seller->id,
            'catalog_product_id' => $line->catalog_product_id,
            'type' => 'transfer',
            'qty_change' => -$pieces,
            'balance_after' => $sellerStock->fresh()->quantity,
            'ref_type' => 'branch_order',
            'ref_id' => $orderId,
            'moved_on' => $today,
            'created_by' => $approverId,
        ]);

        $isVessel = $line->material === 'vessel';
        app(StockTransferService::class)->record([
            'source_branch_id' => $seller->id,
            'destination_branch_id' => $buyerId,
            'catalog_product_id' => $line->catalog_product_id,
            'weight' => $isVessel ? 0 : $weight,
            'quantity' => $isVessel ? $weight : 0,
            'unit_rate' => (float) $line->rate,
            'transfer_date' => $today,
            'created_by' => $approverId,
        ]);
    }

    public function reject(BranchOrderRequest $request, ?int $approverId = null): void
    {
        abort_unless($request->status === 'pending', 422, 'Only pending requests can be rejected.');
        $request->update([
            'status' => 'rejected',
            'approved_by' => $approverId ?? auth()->id(),
            'approved_at' => Carbon::now(),
        ]);

        // Balances charged at submission come back on rejection: the branch wallet for a
        // digi-cash stock order (whole total) or a customised order's wallet share, and the
        // cash-stock share of a customised order.
        $walletRefund = $request->isCustomize()
            ? (float) $request->pay_wallet
            : ($request->payment_type === 'digi_cash' ? (float) $request->grand_total : 0.0);
        if ($walletRefund > 0) {
            Branch::where('id', $request->branch_id)->increment('digi_cash_balance', $walletRefund);
        }
        if ($request->isCustomize() && (float) $request->pay_cash > 0) {
            $this->creditCashStock($request->branch_id, (float) $request->pay_cash, $request->id, $approverId ?? auth()->id());
        }

        // Coins not yet collected are simply not collected; already-collected coins stay
        // in the branch stock for the distributor to hand back to the customer.
        if ($request->sales_return_id && ($sr = $request->salesReturn) && $sr->status === SalesReturn::STATUS_PENDING) {
            app(SalesReturnService::class)->cancel($sr);
        }

        \App\Services\Push\Notifier::to(
            Branch::find($request->branch_id)?->distributorUser?->memberAccount,
            'order',
            'Stock order rejected — ' . $request->request_no,
            'Head Office rejected your stock order of ₹' . \App\Support\Money::group((float) $request->grand_total)
                . ($walletRefund > 0 || (float) $request->pay_cash > 0 ? '. Your payment has been refunded.' : '.'),
            route: '/stock-orders/' . $request->id,
        );
    }

    /** Server-side price of one line from the catalog product + live rate. Cash = rupee value. */
    public function priceLine(CatalogProduct $cp, float $weight, ?LiveRate $rate): array
    {
        if ($cp->material === 'cash') {
            $total = round($weight, 2);

            return [
                'rate' => 1.0, 'making_charge_pct' => 0.0, 'wastage_charge_pct' => 0.0,
                'hallmark_charge' => 0.0, 'gst_pct' => 0.0,
                'subtotal' => $total, 'gst' => 0.0, 'line_total' => $total,
            ];
        }

        $per = $cp->material === 'silver' ? (float) ($rate->silver ?? 0) : (float) ($rate->gold ?? 0);
        $matValue = $weight * $per;
        $making = $matValue * (float) $cp->making_charge_pct / 100;
        $wastage = $matValue * (float) $cp->wastage_charge_pct / 100;
        $hallmark = (float) $cp->hallmark_charge;
        $subtotal = $matValue + $making + $wastage + $hallmark;
        $gst = $subtotal * (float) $cp->gst_pct / 100;

        return [
            'rate' => round($per, 2),
            'making_charge_pct' => (float) $cp->making_charge_pct,
            'wastage_charge_pct' => (float) $cp->wastage_charge_pct,
            'hallmark_charge' => round($hallmark, 2),
            'gst_pct' => (float) $cp->gst_pct,
            'subtotal' => round($subtotal, 2),
            'gst' => round($gst, 2),
            'line_total' => round($subtotal + $gst, 2),
        ];
    }

    protected function nextRequestNo(): string
    {
        $n = (int) BranchOrderRequest::max('id') + 1;

        return 'ORD-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
