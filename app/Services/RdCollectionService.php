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

    /** Redeemable QR minted by the latest collect(), delivered after commit. */
    protected ?int $pendingQrId = null;

    /** Bond of the latest collect(), for the post-commit contract re-send. */
    protected ?int $pendingBondId = null;

    /**
     * @param  array  $data  keys: bond_id, branch_id, amount, cash_stock_id?, paid_on?, created_by?
     */
    public function collect(array $data): RdEntry
    {
        $entry = DB::transaction(function () use ($data) {
            $bond = Bond::with('plan')->findOrFail($data['bond_id']);
            $plan = $bond->plan;
            $branchId = (int) $data['branch_id'];
            $paidOn = $data['paid_on'] ?? Carbon::now()->toDateString();
            $userId = $data['created_by'] ?? auth()->id();
            $amount = round((float) $data['amount'], 2);

            abort_unless($plan && $plan->type === 'rd', 422, 'Not an RD / Gold-Saving bond.');
            abort_unless($bond->status === 'active', 422, 'This bond is not active.');

            // G11 gold-QR plans (rd_qr_grams): the renewal amount is FIXED at the current
            // 100 mg gold price (+making/wastage/GST) — the branch user cannot edit it.
            $fixedAmount = (float) $plan->rd_qr_grams > 0
                ? app(\App\Services\Qr\GoldQrPricing::class)->price($plan)
                : null;
            if ($fixedAmount !== null) {
                $amount = $fixedAmount;
            }
            abort_unless($amount > 0, 422, 'Installment amount must be greater than zero.');

            $this->assertWithinRdLimit($bond, $plan, $amount, $fixedAmount !== null);

            // Currency context: the installment is collected in the BRANCH's currency;
            // the rate to INR is frozen on the entry (India = INR/1.0, unchanged).
            $branch = Branch::find($branchId);
            $fx = $branch?->fxRateToBase() ?? 1.0;
            $currency = $branch?->currency_code ?: 'INR';

            // due sequence number = installments already collected + 1
            $dueCount = RdEntry::where('bond_id', $bond->id)->count() + 1;

            $entry = RdEntry::create([
                'bond_id' => $bond->id,
                'member_id' => $bond->member_id,
                'paid_on' => $paidOn,
                'value' => $amount,
                'due_count' => $dueCount,
                'branch_id' => $branchId,
                'currency_code' => $currency,
                'fx_rate' => $fx,
            ]);

            if (! empty($data['cash_stock_id'])) {
                $this->deductCashStock((int) $data['cash_stock_id'], $branchId, $amount, $paidOn, $userId);
            }

            $this->payBillMargin($bond, $branchId, $userId, $amount, $paidOn, $currency, $fx);
            $this->mintRenewalQr($bond, $plan, $dueCount, $fixedAmount);
            $this->refreshContract($bond);

            return $entry;
        });

        // Post-commit: WhatsApp the updated contract (+ any renewal QR). Never inside the tx.
        $this->sendRenewalDocs();

        return $entry;
    }

    /**
     * RD ceiling (schema v2 item 12): a bond can never collect beyond its plan's
     * validity. Fixed-amount (gold-QR) plans count installments — joining + renewals
     * ≤ validity months. Variable plans (PLUS3) cap the rupee total at monthly ×
     * validity and only accept whole multiples of the monthly amount.
     */
    protected function assertWithinRdLimit(Bond $bond, $plan, float $amount, bool $fixed): void
    {
        $validity = (int) $plan->validity_months;
        if ($validity <= 0) {
            return;
        }

        if ($fixed) {
            // joining consumed installment 1; renewals may fill the remaining validity-1
            $collected = RdEntry::where('bond_id', $bond->id)->count();
            abort_if($collected + 1 > $validity - 1, 422,
                "RD limit reached: this {$validity}-month scheme has no dues left to renew.");

            return;
        }

        $monthly = (float) $bond->value;
        if ($monthly <= 0) {
            return;     // no monthly baseline on record — nothing to enforce against
        }

        $units = $amount / $monthly;
        abort_if(abs($units - round($units)) > 0.001, 422,
            'Renewal must be a whole multiple of the monthly amount (₹' . number_format($monthly, 2) . ').');

        $paid = $monthly + (float) RdEntry::where('bond_id', $bond->id)->sum('value');
        abort_if($paid + $amount > $monthly * $validity + 0.01, 422,
            "RD limit reached: total collections cannot exceed {$validity} × the monthly amount.");
    }

    /**
     * G11 renewal QRs: 'always' (PLUS1) mints a gold QR on every renewal — 11 over the
     * scheme's life including the billing QR; 'first_renewal' (PLUS2) mints its single
     * QR only on renewal #1 (none at billing, none later).
     */
    protected function mintRenewalQr(Bond $bond, $plan, int $dueCount, ?float $worth): void
    {
        if (! $plan->is_redeem || $worth === null || $worth <= 0) {
            return;
        }
        $shouldMint = $plan->rd_qr_on === 'always'
            || ($plan->rd_qr_on === 'first_renewal' && $dueCount === 1);
        if (! $shouldMint) {
            return;
        }

        $this->pendingQrId = app(\App\Services\Qr\RedeemableQrService::class)
            ->mintSettlementQr($bond, $worth)->id;
    }

    /**
     * Item 11: every renewal refreshes the contract body so the monthly chart shows the
     * real paid dates/amounts/branches; the updated PDF is re-sent after commit.
     */
    protected function refreshContract(Bond $bond): void
    {
        $this->pendingBondId = $bond->id;
        $contract = \App\Models\MemberContract::where('bond_id', $bond->id)->latest('id')->first();
        if ($contract) {
            $bond->setRelation('plan', $bond->plan);
            $contract->update(['content' => app(\App\Services\Contract\ContractContentBuilder::class)->build($bond)]);
        }
    }

    /** WhatsApp the updated contract (+ renewal QR if minted) — best-effort, skipped in tests. */
    protected function sendRenewalDocs(): void
    {
        [$qrId, $bondId] = [$this->pendingQrId, $this->pendingBondId];
        $this->pendingQrId = $this->pendingBondId = null;

        if (app()->runningUnitTests() || ! $bondId) {
            return;
        }

        try {
            if ($qrId && ($qr = \App\Models\RedeemableQr::find($qrId))) {
                app(\App\Services\Qr\RedeemableQrService::class)->deliver($qr);   // contract + QR

                return;
            }
            $bond = Bond::with('member', 'plan')->find($bondId);
            if ($bond?->member?->phone && $bond->plan?->is_contract) {
                app(\App\Services\Whatsapp\WhatsappSender::class)->sendMedia(
                    $bond->member->phone,
                    app(\App\Services\Contract\ContractService::class)->store($bond),
                    'Dear ' . $bond->member->name . ', your updated Lord Jeweller contract ' . $bond->invoice_no . ' is attached.'
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('RD renewal document send failed: ' . $e->getMessage());
        }
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

    /**
     * Branch RENEWAL margin on the collection (legacy tbl_reseller_com), skipped for HQ.
     * Schema v2: renewals pay the plan's renewal_margin, not the sale billing_margin.
     */
    protected function payBillMargin(Bond $bond, int $branchId, ?int $userId, float $amount, string $paidOn, string $currency = 'INR', float $fx = 1.0): void
    {
        $plan = $bond->plan;
        if ($branchId === self::HQ_BRANCH_ID || ! $plan || (float) $plan->renewal_margin == 0.0) {
            return;
        }
        // Margin computes in the branch currency; the ledger stores INR base
        // (caps stay coherent), the branch balance shows its own currency —
        // same policy as SalesService::payBillMargin.
        $marginLocal = round($amount * (float) $plan->renewal_margin / 100, 2);
        if ($marginLocal <= 0) {
            return;
        }
        ResellerCommission::create([
            'bill_date' => $paidOn,
            'invoice_no' => 'RD-' . $bond->id . '-' . $bond->rdEntries()->count(),
            'com_type_id' => ResellerCommission::COM_RD_RENEWAL_MARGIN,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'com_value' => round($marginLocal * $fx, 2),
            'currency_code' => $currency,
            'fx_rate' => $fx,
            'reference_member_id' => $bond->member_id,
            'status' => 'passed',
        ]);
        Branch::where('id', $branchId)->increment('bill_margin', $marginLocal);
    }
}
