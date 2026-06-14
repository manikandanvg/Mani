<?php

namespace App\Services;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\RdEntry;
use App\Models\ResellerCommission;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Collects a single installment ("renewal") against an EXISTING RD / Gold-Saving bond —
 * the legacy admin/trade/rdsales flow. Opening a new RD happens in SalesService; this only
 * adds the monthly collection: a tbl_rdentry row, a cash-stock deduction, and the branch
 * bill-margin (excluding HQ).
 */
class RdCollectionService
{
    /** Head office: its own collections do not earn a branch bill-margin. */
    public const HQ_BRANCH_ID = 1;

    /**
     * @param  array  $data  keys: bond_id, branch_id, amount, cash_stock_id?, paid_on?, created_by?
     */
    public function collect(array $data): RdEntry
    {
        return DB::transaction(function () use ($data) {
            $bond = Bond::with('plan')->findOrFail($data['bond_id']);
            $branchId = (int) $data['branch_id'];
            $paidOn = $data['paid_on'] ?? Carbon::now()->toDateString();
            $userId = $data['created_by'] ?? auth()->id();
            $amount = round((float) $data['amount'], 2);

            abort_unless($bond->plan && $bond->plan->type === 'rd', 422, 'Not an RD / Gold-Saving bond.');
            abort_unless($bond->status === 'active', 422, 'This bond is not active.');
            abort_unless($amount > 0, 422, 'Installment amount must be greater than zero.');

            // due sequence number = installments already collected + 1
            $dueCount = RdEntry::where('bond_id', $bond->id)->count() + 1;

            $entry = RdEntry::create([
                'bond_id' => $bond->id,
                'member_id' => $bond->member_id,
                'paid_on' => $paidOn,
                'value' => $amount,
                'due_count' => $dueCount,
                'branch_id' => $branchId,
            ]);

            if (! empty($data['cash_stock_id'])) {
                $this->deductCashStock((int) $data['cash_stock_id'], $branchId, $amount, $paidOn, $userId);
            }

            $this->payBillMargin($bond, $branchId, $userId, $amount, $paidOn);

            return $entry;
        });
    }

    /** Cash stock is held as rupee value (CashStockSeeder), so we deduct the collected amount. */
    protected function deductCashStock(int $stockId, int $branchId, float $amount, string $paidOn, ?int $userId): void
    {
        $stock = Stock::where('id', $stockId)->where('branch_id', $branchId)->first();
        if (! $stock) {
            return;
        }
        $stock->decrement('quantity', $amount);
        StockMovement::create([
            'branch_id' => $branchId,
            'catalog_product_id' => $stock->catalog_product_id,
            'type' => 'sale',
            'qty_change' => -$amount,
            'balance_after' => $stock->fresh()->quantity,
            'ref_type' => 'rd_entry',
            'moved_on' => $paidOn,
            'created_by' => $userId,
        ]);
    }

    /** Branch billing margin on the collection (legacy tbl_reseller_com), skipped for HQ. */
    protected function payBillMargin(Bond $bond, int $branchId, ?int $userId, float $amount, string $paidOn): void
    {
        $plan = $bond->plan;
        if ($branchId === self::HQ_BRANCH_ID || ! $plan || (float) $plan->billing_margin == 0.0) {
            return;
        }
        $margin = round($amount * (float) $plan->billing_margin / 100, 2);
        if ($margin <= 0) {
            return;
        }
        ResellerCommission::create([
            'bill_date' => $paidOn,
            'invoice_no' => 'RD-' . $bond->id . '-' . $bond->rdEntries()->count(),
            'com_type_id' => 2,   // 2 = RD/GS renewal margin (1 = sale)
            'user_id' => $userId,
            'branch_id' => $branchId,
            'com_value' => $margin,
            'reference_member_id' => $bond->member_id,
            'status' => 'passed',
        ]);
        Branch::where('id', $branchId)->increment('bill_margin', $margin);
    }
}
