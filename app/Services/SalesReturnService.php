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

        return SalesReturn::create([
            'return_no' => $this->nextReturnNo(),
            'branch_id' => $branchId,
            'bill_no' => $billNo,
            'member_id' => $data['member_id'] ?? null,
            'catalog_product_id' => $cp->id,
            'material' => $material,
            'quantity' => $qty,
            'grams' => $grams,
            'rate' => round($perGram, 2),
            'credit_value' => CustomizeOrderPricing::metalValue($material, $grams, $rate),
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

    /** Preview the credit a coin return would carry, without persisting. */
    public function quote(?int $catalogProductId, float $qty): array
    {
        $cp = $catalogProductId ? CatalogProduct::find($catalogProductId) : CustomizeOrderPricing::coinProduct();
        if (! $cp || $qty <= 0) {
            return ['grams' => 0.0, 'rate' => 0.0, 'value' => 0.0, 'material' => $cp?->material ?? 'gold'];
        }
        $material = in_array($cp->material, ['gold', 'silver'], true) ? $cp->material : 'gold';
        $grams = round($cp->gramsFromPieces($qty), 4);

        return [
            'material' => $material,
            'grams' => $grams,
            'rate' => CustomizeOrderPricing::liveRate($material),
            'value' => CustomizeOrderPricing::metalValue($material, $grams),
        ];
    }

    /** The distributor physically received the coins: count them into branch stock. */
    public function markCollected(SalesReturn $return, ?int $userId = null): void
    {
        DB::transaction(function () use ($return, $userId) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            abort_unless($return->status === SalesReturn::STATUS_PENDING, 422, 'Only pending returns can be marked collected.');

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
