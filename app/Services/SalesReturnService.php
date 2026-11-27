<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Support\CustomizeOrderPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer sales returns (board 2026-08-26). A distributor takes back a customer's
 * coins / metal — typically the accumulated RD 100 mg gold coins — values them at the
 * live metal rate, and (when raised from the Customize Order Form) applies that value
 * as `coin_credit` on the order. The coins are collected at the agreed date & time
 * (→ branch stock), then relayed to the supplier when the order is approved (→ out
 * of the branch's inventory).
 */
class SalesReturnService
{
    /**
     * @param array $data keys: branch_id, member_id?, catalog_product_id?, material?,
     *                    quantity (pieces), collect_on?, notes?, created_by?
     */
    public function create(array $data): SalesReturn
    {
        $branchId = (int) $data['branch_id'];
        $cp = ! empty($data['catalog_product_id']) ? CatalogProduct::find($data['catalog_product_id']) : CustomizeOrderPricing::coinProduct();
        abort_if(! $cp, 422, 'No coin product is configured — set the Gold Saving coin catalog item under Commission Setup → Customize Order pricing.');

        $qty = (float) ($data['quantity'] ?? 0);
        abort_if($qty <= 0, 422, 'Enter how many coins / pieces the customer is returning.');

        $material = in_array($cp->material, ['gold', 'silver'], true) ? $cp->material : ($data['material'] ?? 'gold');
        $grams = round($cp->gramsFromPieces($qty), 4);
        abort_if($grams <= 0, 422, 'The selected item has no per-piece weight — pick a coin / bar product.');

        $rate = LiveRate::latestFor('IN') ?? LiveRate::query()->latest('id')->first();
        $perGram = CustomizeOrderPricing::liveRate($material, $rate);
        abort_if($perGram <= 0, 422, 'No live ' . $material . ' rate configured — set it before valuing returned coins.');

        // A return typed by hand names its bill (board 2026-11-27); the one the Customize Order
        // Form raises for a customer's Gold Saving coins carries none.
        $billNo = filled($data['bill_no'] ?? null) ? trim((string) $data['bill_no']) : null;
        if ($billNo !== null || ! empty($data['require_bill'])) {
            $check = $this->checkBill($billNo, ! empty($data['member_id']) ? (int) $data['member_id'] : null, $branchId);
            abort_unless($check['ok'], 422, $check['message']);
        }

        // A return against a bill takes back only what that bill sold (never the cash stock),
        // and a gold coin carries the convenience charge (board 2026-11-27).
        $coinValue = CustomizeOrderPricing::metalValue($material, $grams, $rate);
        $charge = 0.0;
        if ($billNo !== null) {
            $item = $this->billItems($billNo, ! empty($data['member_id']) ? (int) $data['member_id'] : null, $branchId)[$cp->id] ?? null;
            abort_if(! $item, 422, 'This item is not on bill ' . $billNo . ' — pick one of the billed items.');
            abort_if($qty > $item['left'] + 0.0001, 422, 'Bill ' . $billNo . ' has ' . self::num($item['left']) . ' piece(s) of this item left to return ('
                . self::num($item['billed']) . ' billed, ' . self::num($item['returned']) . ' already returned).');
            $charge = self::convenienceCharge($material, $grams);
        }

        return SalesReturn::create([
            'convenience_charge' => $charge,
            'total_value' => round($coinValue + $charge, 2),
            'return_no' => $this->nextReturnNo(),
            'branch_id' => $branchId,
            'bill_no' => $billNo,
            'member_id' => $data['member_id'] ?? null,
            'catalog_product_id' => $cp->id,
            'material' => $material,
            'quantity' => $qty,
            'grams' => $grams,
            'rate' => round($perGram, 2),
            'credit_value' => $coinValue,
            'collect_on' => ! empty($data['collect_on']) ? Carbon::parse($data['collect_on']) : null,
            'status' => SalesReturn::STATUS_PENDING,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? auth()->id(),
        ]);
    }

    /**
     * Board 2026-11-27: a sales return names the bill the distributor bought on. The bill must
     * exist, be a bill of THIS branch and be billed to THIS distributor — a branch takes back
     * only what it sold, from the person it sold it to.
     *
     * @return array{ok: bool, message: string, bill?: array{no: string, kind: string, date: ?string, amount: float}}
     */
    public function checkBill(?string $billNo, ?int $memberId, ?int $branchId): array
    {
        $billNo = trim((string) $billNo);
        if ($billNo === '') {
            return ['ok' => false, 'message' => 'Enter the branch bill number.'];
        }
        if (! $memberId) {
            return ['ok' => false, 'message' => 'Pick the distributor the bill was made for.'];
        }

        // the three kinds of bill a distributor holds: the sale, its contract, the redemption
        $found = collect();
        foreach (DB::table('sales_invoices')->where('invoice_no', $billNo)->get() as $b) {
            $found->push(['kind' => 'Sales bill', 'member' => $b->customer_member_id, 'branch' => $b->branch_id, 'date' => $b->date,
                'amount' => (float) $b->grand_total, 'dead' => $b->status === 'cancelled' || $b->cancelled_at !== null]);
        }
        foreach (DB::table('bonds')->where('invoice_no', $billNo)->get() as $b) {
            $found->push(['kind' => 'Contract bill', 'member' => $b->member_id, 'branch' => $b->branch_id, 'date' => $b->bond_date,
                'amount' => (float) ($b->epin_value ?: $b->value), 'dead' => false]);
        }
        foreach (DB::table('redemption_invoices')->where('invoice_no', $billNo)->get() as $b) {
            $found->push(['kind' => 'Redemption invoice', 'member' => $b->member_id, 'branch' => $b->branch_id, 'date' => $b->invoice_date,
                'amount' => (float) $b->grand_total, 'dead' => $b->deleted_at !== null]);
        }

        if ($found->isEmpty()) {
            return ['ok' => false, 'message' => 'Bill ' . $billNo . ' was not found.'];
        }
        $live = $found->where('dead', false);
        if ($live->isEmpty()) {
            return ['ok' => false, 'message' => 'Bill ' . $billNo . ' is cancelled.'];
        }
        $ofMember = $live->filter(fn ($b) => (int) $b['member'] === (int) $memberId);
        if ($ofMember->isEmpty()) {
            return ['ok' => false, 'message' => 'Bill ' . $billNo . ' was not billed to this distributor.'];
        }
        $bill = $branchId ? $ofMember->first(fn ($b) => (int) $b['branch'] === (int) $branchId) : $ofMember->first();
        if (! $bill) {
            return ['ok' => false, 'message' => 'Bill ' . $billNo . ' is not a bill of this branch — the return is taken by the branch that billed it.'];
        }

        return ['ok' => true, 'message' => $bill['kind'] . ' ' . $billNo . ' — ' . Carbon::parse($bill['date'])->format('d M Y')
            . ' — ₹' . \App\Support\Money::group($bill['amount']),
            'bill' => ['no' => $billNo, 'kind' => $bill['kind'], 'date' => $bill['date'], 'amount' => $bill['amount']]];
    }

    /**
     * Convenience charge on a returned GOLD coin, in ₹ a gram, until the admin sets another on
     * Commission Setup → Sales Return. Head Office adds it to the coin value: taking the coin
     * back saves it the voucher re-printing and packing cost (user 2026-11-28).
     */
    public const CONVENIENCE_PER_GRAM = 50;

    public const SETTING_GROUP = 'sales_return';

    public static function convenienceRate(): float
    {
        $v = \App\Models\Setting::where('group', self::SETTING_GROUP)->where('key', 'convenience_per_g')->value('value');

        return $v === null || $v === '' ? (float) self::CONVENIENCE_PER_GRAM : max(0.0, (float) $v);
    }

    public static function saveConvenienceRate(float $perGram): void
    {
        \App\Models\Setting::updateOrCreate(
            ['group' => self::SETTING_GROUP, 'key' => 'convenience_per_g'],
            ['value' => (string) max(0.0, $perGram), 'type' => 'float'],
        );
    }

    /** At ₹50 a gram: 1 g gold → 50, 100 mg → 5, 10 g → 500; silver carries none. */
    public static function convenienceCharge(?string $material, float $grams): float
    {
        return $material === 'gold' ? round($grams * self::convenienceRate(), 2) : 0.0;
    }

    /**
     * The gold / silver items a bill sold, in pieces, less what was already returned on it — the
     * only things a return against that bill may take back. Cash stock on the bill is left out.
     * Empty when the bill does not pass checkBill().
     *
     * @return array<int, array{label: string, material: string, billed: float, returned: float, left: float}>
     */
    public function billItems(?string $billNo, ?int $memberId, ?int $branchId): array
    {
        $billNo = trim((string) $billNo);
        if (! $this->checkBill($billNo, $memberId, $branchId)['ok']) {
            return [];
        }

        $billed = [];   // catalog_product_id => pieces
        $sale = DB::table('sale_lines')->join('sales_invoices', 'sales_invoices.id', '=', 'sale_lines.invoice_id')
            ->where('sales_invoices.invoice_no', $billNo)->where('sales_invoices.customer_member_id', $memberId)
            ->when($branchId, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
            ->where(fn ($q) => $q->whereNull('sales_invoices.status')->orWhere('sales_invoices.status', '!=', 'cancelled'))
            ->whereNull('sales_invoices.cancelled_at')
            ->whereNotNull('sale_lines.catalog_product_id')
            ->get(['sale_lines.catalog_product_id', 'sale_lines.qty']);
        $redeemed = DB::table('redemption_lines')->join('redemption_invoices', 'redemption_invoices.id', '=', 'redemption_lines.redemption_invoice_id')
            ->where('redemption_invoices.invoice_no', $billNo)->where('redemption_invoices.member_id', $memberId)
            ->when($branchId, fn ($q) => $q->where('redemption_invoices.branch_id', $branchId))
            ->whereNull('redemption_invoices.deleted_at')->whereNotNull('redemption_lines.catalog_product_id')
            ->get(['redemption_lines.catalog_product_id', 'redemption_lines.quantity']);

        $products = CatalogProduct::whereIn('id', $sale->pluck('catalog_product_id')->merge($redeemed->pluck('catalog_product_id'))->unique())
            ->whereIn('material', ['gold', 'silver'])->get()->keyBy('id');
        // A bill made in this system ("INV-…") holds the WEIGHT in grams on its lines; a bill
        // carried over from the old system (its own number) holds the number of PIECES.
        $inGrams = str_starts_with(strtoupper($billNo), 'INV-');
        foreach ($sale as $l) {
            if ($cp = $products[$l->catalog_product_id] ?? null) {
                $billed[$cp->id] = ($billed[$cp->id] ?? 0) + ($inGrams ? $cp->piecesFromWeight((float) $l->qty) : (float) $l->qty);
            }
        }
        foreach ($redeemed as $l) {   // a redemption line holds pieces
            if (isset($products[$l->catalog_product_id])) {
                $billed[$l->catalog_product_id] = ($billed[$l->catalog_product_id] ?? 0) + (float) $l->quantity;
            }
        }

        // A sales bill and the redemption invoice of its contract name the SAME coins: a coin
        // returned on one number counts as returned on the other too.
        $bondIds = DB::table('bonds')->where('invoice_no', $billNo)->pluck('id')
            ->merge(DB::table('redemption_invoices')->where('invoice_no', $billNo)->whereNotNull('bond_id')->pluck('bond_id'))->unique();
        $numbers = collect([$billNo])
            ->merge(DB::table('bonds')->whereIn('id', $bondIds)->pluck('invoice_no'))
            ->merge(DB::table('redemption_invoices')->whereIn('bond_id', $bondIds)->whereNull('deleted_at')->pluck('invoice_no'))
            ->filter()->unique()->values();

        $returned = SalesReturn::whereIn('bill_no', $numbers)->where('status', '!=', SalesReturn::STATUS_CANCELLED)
            ->selectRaw('catalog_product_id, SUM(quantity) AS n')->groupBy('catalog_product_id')->pluck('n', 'catalog_product_id');

        $out = [];
        foreach ($billed as $id => $pieces) {
            $cp = $products[$id];
            if ($cp->unitWeight() <= 0 || $pieces <= 0) {
                continue;
            }
            $done = (float) ($returned[$id] ?? 0);
            $out[$id] = [
                'label' => $cp->code . ' — ' . \App\Support\Translatable::pick($cp->name) . ' (' . strtoupper($cp->material) . ' · '
                    . self::num($cp->unitWeight()) . ' g/pc) — billed ' . self::num($pieces) . ($done > 0 ? ', returned ' . self::num($done) : ''),
                'material' => $cp->material,
                'billed' => round($pieces, 4),
                'returned' => $done,
                'left' => max(0.0, round($pieces - $done, 4)),
            ];
        }

        return $out;
    }

    /**
     * The distributor's live bills at this branch that sold a gold / silver item — the choice
     * offered for "Branch bill number", newest first.
     *
     * @return array<string, string> bill number => label
     */
    public function billOptions(?int $memberId, ?int $branchId): array
    {
        if (! $memberId) {
            return [];
        }
        $sales = DB::table('sales_invoices')->join('sale_lines', 'sale_lines.invoice_id', '=', 'sales_invoices.id')
            ->join('catalog_products', 'catalog_products.id', '=', 'sale_lines.catalog_product_id')
            ->whereIn('catalog_products.material', ['gold', 'silver'])
            ->where('sales_invoices.customer_member_id', $memberId)
            ->when($branchId, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
            ->where(fn ($q) => $q->whereNull('sales_invoices.status')->orWhere('sales_invoices.status', '!=', 'cancelled'))
            ->whereNull('sales_invoices.cancelled_at')
            ->distinct()->get(['sales_invoices.invoice_no', 'sales_invoices.date', 'sales_invoices.grand_total'])
            ->map(fn ($b) => ['no' => $b->invoice_no, 'kind' => 'Sales bill', 'date' => (string) $b->date, 'amount' => (float) $b->grand_total]);
        $redeemed = DB::table('redemption_invoices')->join('redemption_lines', 'redemption_lines.redemption_invoice_id', '=', 'redemption_invoices.id')
            ->join('catalog_products', 'catalog_products.id', '=', 'redemption_lines.catalog_product_id')
            ->whereIn('catalog_products.material', ['gold', 'silver'])
            ->where('redemption_invoices.member_id', $memberId)
            ->when($branchId, fn ($q) => $q->where('redemption_invoices.branch_id', $branchId))
            ->whereNull('redemption_invoices.deleted_at')
            ->leftJoin('bonds', 'bonds.id', '=', 'redemption_invoices.bond_id')
            ->distinct()->get(['redemption_invoices.invoice_no', 'redemption_invoices.invoice_date', 'redemption_invoices.grand_total', 'bonds.invoice_no as sale_no'])
            // one purchase, one line: the redemption invoice of a sales bill already listed
            // names the same coins, so only the sales bill is offered
            ->reject(fn ($b) => filled($b->sale_no) && $sales->contains('no', $b->sale_no))
            ->map(fn ($b) => ['no' => $b->invoice_no, 'kind' => 'Redemption invoice', 'date' => (string) $b->invoice_date, 'amount' => (float) $b->grand_total]);

        return $sales->concat($redeemed)->filter(fn ($b) => filled($b['no']))->sortByDesc('date')
            ->mapWithKeys(fn ($b) => [(string) $b['no'] => $b['no'] . ' — ' . $b['kind'] . ' — ' . Carbon::parse($b['date'])->format('d M Y')
                . ' — ₹' . \App\Support\Money::group($b['amount'])])->all();
    }

    /**
     * The distributors a branch has billed a gold / silver item to (a live sales bill or a
     * redemption invoice) — exactly those billOptions() has a bill for. A distributor whose
     * bills here are cash stock only has nothing to return and is not offered.
     */
    public function billedMembers(?int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Models\Member::query()->where(fn ($w) => $w
            ->whereIn('id', DB::table('sales_invoices')->select('sales_invoices.customer_member_id')
                ->join('sale_lines', 'sale_lines.invoice_id', '=', 'sales_invoices.id')
                ->join('catalog_products', 'catalog_products.id', '=', 'sale_lines.catalog_product_id')
                ->whereIn('catalog_products.material', ['gold', 'silver'])
                ->whereNotNull('sales_invoices.customer_member_id')
                ->when($branchId, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
                ->where(fn ($q) => $q->whereNull('sales_invoices.status')->orWhere('sales_invoices.status', '!=', 'cancelled'))
                ->whereNull('sales_invoices.cancelled_at'))
            ->orWhereIn('id', DB::table('redemption_invoices')->select('redemption_invoices.member_id')
                ->join('redemption_lines', 'redemption_lines.redemption_invoice_id', '=', 'redemption_invoices.id')
                ->join('catalog_products', 'catalog_products.id', '=', 'redemption_lines.catalog_product_id')
                ->whereIn('catalog_products.material', ['gold', 'silver'])
                ->whereNotNull('redemption_invoices.member_id')
                ->when($branchId, fn ($q) => $q->where('redemption_invoices.branch_id', $branchId))
                ->whereNull('redemption_invoices.deleted_at')));
    }

    protected static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    /**
     * Preview the value a coin return would carry, without persisting. `charge` (and so `total`)
     * is filled only when $withCharge — a return typed against a bill.
     */
    public function quote(?int $catalogProductId, float $qty, bool $withCharge = false): array
    {
        $cp = $catalogProductId ? CatalogProduct::find($catalogProductId) : CustomizeOrderPricing::coinProduct();
        if (! $cp || $qty <= 0) {
            return ['grams' => 0.0, 'rate' => 0.0, 'value' => 0.0, 'charge' => 0.0, 'total' => 0.0, 'material' => $cp?->material ?? 'gold'];
        }
        $material = in_array($cp->material, ['gold', 'silver'], true) ? $cp->material : 'gold';
        $grams = round($cp->gramsFromPieces($qty), 4);
        $value = CustomizeOrderPricing::metalValue($material, $grams);
        $charge = $withCharge ? self::convenienceCharge($material, $grams) : 0.0;

        return [
            'material' => $material,
            'grams' => $grams,
            'rate' => CustomizeOrderPricing::liveRate($material),
            'value' => $value,
            'charge' => $charge,
            'total' => round($value + $charge, 2),
        ];
    }

    public const HQ_BRANCH_ID = 1;

    /**
     * A return typed against a bill settles with Head Office the moment the admin confirms the
     * coins (user 2026-11-28); one raised by the Customize Order Form waits for its order.
     */
    public static function settlesWithHq(SalesReturn $return): bool
    {
        return filled($return->bill_no) && ! $return->orderRequest;
    }

    /**
     * The coins were received. A return against a bill: the coins go to HEAD OFFICE stock and
     * its value (coin value + convenience charge) is credited to the branch's Digi cash, like
     * an approved stock return. A Customize Order return: the coins wait in the branch's stock
     * until the order is approved (relay()).
     */
    public function markCollected(SalesReturn $return, ?int $userId = null): void
    {
        $settled = 0.0;
        DB::transaction(function () use ($return, $userId, &$settled) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            abort_unless($return->status === SalesReturn::STATUS_PENDING, 422, 'Only pending returns can be marked collected.');

            if (self::settlesWithHq($return)) {
                if ($return->catalog_product_id) {
                    $hq = Stock::firstOrCreate(
                        ['branch_id' => self::HQ_BRANCH_ID, 'catalog_product_id' => $return->catalog_product_id, 'order_line_id' => 0],
                        ['quantity' => 0],
                    );
                    $hq->increment('quantity', (float) $return->quantity);
                    StockMovement::create([
                        'branch_id' => self::HQ_BRANCH_ID,
                        'catalog_product_id' => $return->catalog_product_id,
                        'type' => 'sales_return',
                        'qty_change' => (float) $return->quantity,
                        'balance_after' => $hq->fresh()->quantity,
                        'ref_type' => 'sales_return',
                        'ref_id' => $return->id,
                        'note' => 'Returned coins received from ' . ($return->branch?->name ?? 'branch') . ' (' . $return->return_no . ', bill ' . $return->bill_no . ')',
                        'moved_on' => Carbon::now()->toDateString(),
                        'created_by' => $userId ?? auth()->id(),
                    ]);
                }
                // Head Office does not pay itself
                if ((int) $return->branch_id !== self::HQ_BRANCH_ID) {
                    $settled = round((float) ($return->total_value ?? $return->credit_value), 2);
                    Branch::where('id', $return->branch_id)->increment('digi_cash_balance', $settled);
                }
                $return->update([
                    'status' => SalesReturn::STATUS_COLLECTED,
                    'collected_at' => Carbon::now(),
                    'settled_amount' => $settled,
                    'settled_at' => Carbon::now(),
                ]);

                return;
            }

            if ($return->catalog_product_id) {
                $stock = Stock::firstOrCreate(
                    ['branch_id' => $return->branch_id, 'catalog_product_id' => $return->catalog_product_id],
                    ['quantity' => 0],
                );
                $stock->increment('quantity', (float) $return->quantity);
                StockMovement::create([
                    'branch_id' => $return->branch_id,
                    'catalog_product_id' => $return->catalog_product_id,
                    'type' => 'sales_return',
                    'qty_change' => (float) $return->quantity,
                    'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'sales_return',
                    'ref_id' => $return->id,
                    'note' => 'Customer coins collected (' . $return->return_no . ')',
                    'moved_on' => Carbon::now()->toDateString(),
                    'created_by' => $userId ?? auth()->id(),
                ]);
            }

            $return->update([
                'status' => SalesReturn::STATUS_COLLECTED,
                'collected_at' => Carbon::now(),
            ]);
        });

        if ($settled > 0) {
            \App\Services\Push\Notifier::to(
                Branch::find($return->branch_id)?->distributorUser?->memberAccount,
                'wallet',
                'Sales return settled — ' . $return->return_no,
                \App\Support\Money::inr($settled) . ' has been credited to your branch Digi cash wallet.',
                route: '/wallet',
            );
        }
    }

    /**
     * Pass the collected coins on to the supplier (order approved): out of the
     * distributor's stock, into the seller's when the seller is a branch (HQ or a
     * dealer); a missing seller means the coins simply leave the branch.
     */
    public function relay(SalesReturn $return, ?Branch $seller, ?int $userId = null): void
    {
        DB::transaction(function () use ($return, $seller, $userId) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            abort_unless($return->status === SalesReturn::STATUS_COLLECTED, 422,
                'Coins on ' . $return->return_no . ' have not been collected yet — mark them collected on Sales Returns first.');

            if ($return->catalog_product_id) {
                $today = Carbon::now()->toDateString();
                $pieces = (float) $return->quantity;
                $stock = Stock::firstOrCreate(
                    ['branch_id' => $return->branch_id, 'catalog_product_id' => $return->catalog_product_id],
                    ['quantity' => 0],
                );
                $stock->decrement('quantity', $pieces);
                StockMovement::create([
                    'branch_id' => $return->branch_id,
                    'catalog_product_id' => $return->catalog_product_id,
                    'type' => 'transfer',
                    'qty_change' => -$pieces,
                    'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'sales_return',
                    'ref_id' => $return->id,
                    'note' => 'Coins relayed to supplier (' . $return->return_no . ')',
                    'moved_on' => $today,
                    'created_by' => $userId ?? auth()->id(),
                ]);

                if ($seller) {
                    $in = Stock::firstOrCreate(
                        ['branch_id' => $seller->id, 'catalog_product_id' => $return->catalog_product_id],
                        ['quantity' => 0],
                    );
                    $in->increment('quantity', $pieces);
                    StockMovement::create([
                        'branch_id' => $seller->id,
                        'catalog_product_id' => $return->catalog_product_id,
                        'type' => 'transfer',
                        'qty_change' => $pieces,
                        'balance_after' => $in->fresh()->quantity,
                        'ref_type' => 'sales_return',
                        'ref_id' => $return->id,
                        'note' => 'Customer coins received from ' . ($return->branch?->name ?? 'branch') . ' (' . $return->return_no . ')',
                        'moved_on' => $today,
                        'created_by' => $userId ?? auth()->id(),
                    ]);
                }
            }

            $return->update([
                'status' => SalesReturn::STATUS_RELAYED,
                'relayed_at' => Carbon::now(),
            ]);
        });
    }

    public function cancel(SalesReturn $return): void
    {
        abort_unless($return->status === SalesReturn::STATUS_PENDING, 422, 'Only pending returns can be cancelled.');
        $return->update(['status' => SalesReturn::STATUS_CANCELLED]);
    }

    protected function nextReturnNo(): string
    {
        $n = (int) SalesReturn::max('id') + 1;

        return 'SR-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
