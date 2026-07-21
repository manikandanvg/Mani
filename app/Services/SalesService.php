<?php

namespace App\Services;

use App\Models\Bond;
use App\Models\CatalogProduct;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\ResellerCommission;
use App\Models\SaleLine;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trade 2.3 Sales — the transactional "Generate Invoice". Mirrors the legacy
 * lordsalessave but: looped 10-level IC (not 10 copies), one DB transaction, no raw
 * SQL. Income streams: IC (instant, 10 levels, capped), CBC (monthly schedule),
 * level/GAP (paid monthly by the engine), bill-margin (reseller).
 *
 * Assumptions flagged for confirmation (spec vs legacy):
 *  - GBV roll-up uses the ALLOCATED amount (per spec), not grand total (legacy code).
 *  - Earning cap is LIFETIME (bonded BV - all earnings), fixing the legacy daily reset.
 *  - IC base and CBC base are the allocated amount.
 */
class SalesService
{
    public const MAX_IC_LEVELS = 10;

    /** Headquarters branch id — bill-margin is not paid for HQ's own bills. */
    public const HQ_BRANCH_ID = 1;

    /** Redeemable-stock-QR id minted in the current sale, delivered after commit. */
    protected ?int $pendingQr = null;

    /**
     * @param  array  $data  keys: branch_id, bill_date?, created_by?, plan_id,
     *   payment_type?, payment_reference?, gold_rate?, silver_rate?, diamond_rate?,
     *   referrer_code?, upline_code?, customer{member_id|...kyc}, cart[]
     */
    public function generateInvoice(array $data): SalesInvoice
    {
        $invoice = DB::transaction(function () use ($data) {
            $branchId = $data['branch_id'];
            $billDate = $data['bill_date'] ?? Carbon::now()->toDateString();
            $userId = $data['created_by'] ?? auth()->id();
            $plan = Plan::findOrFail($data['plan_id']);

            // Currency context for this sale: the branch's operating currency and the rate
            // (INR per 1 unit) frozen onto every document so the figures never re-derive.
            // India branch => INR / 1.0, which keeps the maths below identical to before.
            $branch = \App\Models\Branch::find($branchId);
            $fx = $branch?->fxRateToBase() ?? 1.0;
            $currency = $branch?->currency_code ?: 'INR';

            [$member, $isNew] = $this->resolveCustomer($data, $billDate, $branchId);

            // --- totals from cart (in the BRANCH currency) ---
            $cart = $data['cart'] ?? [];
            $crossTotal = round(array_sum(array_map(fn ($l) => (float) ($l['net_total'] ?? 0), $cart)), 2);
            $cartGrand = round(array_sum(array_map(fn ($l) => (float) ($l['grand_total'] ?? 0), $cart)), 2);
            // Tax follows the branch regime: GST splits the per-line GST the page computed;
            // VAT/none recompute from the taxable base.
            $tax = $branch
                ? $branch->taxOn($crossTotal, $cartGrand - $crossTotal)
                : ['mode' => 'gst', 'total' => round($cartGrand - $crossTotal, 2), 'cgst' => round(($cartGrand - $crossTotal) / 2, 2), 'sgst' => round(($cartGrand - $crossTotal) / 2, 2)];
            $grandTotal = round($crossTotal + $tax['total'], 2);

            // Network/commission maths run in INR base: convert the allocated amount once.
            $allocation = round($crossTotal * (float) $plan->allocation_pct / 100 * $fx, 2);

            // --- invoice (amounts stored in the branch currency, stamped with code + rate) ---
            $invoice = SalesInvoice::create([
                'invoice_no' => $this->nextInvoiceNo(),
                'date' => $billDate,
                'customer_member_id' => $member->id,
                'customer_name' => $member->name,
                'branch_id' => $branchId,
                'plan_id' => $plan->id,
                'cross_total' => $crossTotal,
                'net_total' => $crossTotal,
                'sgst' => $tax['sgst'],
                'cgst' => $tax['cgst'],
                'tax_total' => $tax['total'],
                'tax_regime' => $tax['mode'],
                'grand_total' => $grandTotal,
                'received' => $grandTotal,
                'currency_code' => $currency,
                'fx_rate' => $fx,
                'payment_type' => $data['payment_type'] ?? 'cash',
                'payment_reference' => $data['payment_reference'] ?? null,
                'gold_rate' => $data['gold_rate'] ?? 0,
                'silver_rate' => $data['silver_rate'] ?? 0,
                'diamond_rate' => $data['diamond_rate'] ?? 0,
                'remarks' => 'SALES',
            ]);

            foreach ($cart as $line) {
                SaleLine::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => null, // catalog item, not storefront product
                    'catalog_product_id' => $line['catalog_product_id'] ?? null,
                    'material' => $line['material'] ?? null,
                    'description' => $line['description'] ?? ($line['material'] ?? null),
                    'qty' => (float) ($line['weight'] ?? 0),
                    'rate' => (float) ($line['rate'] ?? 0),
                    'making' => (float) ($line['making'] ?? 0),
                    'wastage' => (float) ($line['wastage'] ?? 0),
                    'line_total' => (float) ($line['grand_total'] ?? 0),
                ]);
            }

            // --- bond (the commission-bearing unit) ---
            $cbcMonthly = $plan->cbc_count > 0
                ? round($allocation * (float) $plan->cbc_value / 100 / $plan->cbc_count, 2)
                : 0.0;
            $levelAmounts = array_map(
                fn ($pct) => round($allocation * (float) $pct / 100, 2),
                $plan->level_schedule ?? []
            );

            $bond = Bond::create([
                'member_id' => $member->id,
                'plan_id' => $plan->id,
                'branch_id' => $branchId,
                'bond_date' => $billDate,
                'value' => $allocation,                 // bvalue = allocated amount
                'invoice_no' => $invoice->invoice_no,
                'lvlcom' => $levelAmounts,
                'cbc_value' => $cbcMonthly,
                'cbc_count' => (int) $plan->cbc_count,
                'cbc_issued' => 0,
                'lvlcom_count' => (int) ($plan->level_com_duration ?: $plan->validity_months),
                'lvlcom_issued' => 0,
                'epin_value' => round($grandTotal * $fx, 2),   // INR base
                'mou_id' => $plan->mou_id,
                'status' => 'active',
            ]);

            $this->scheduleCbc($bond, $member, $cbcMonthly, (int) $plan->cbc_count, $billDate);
            $this->issueInstantCommission($member, $bond, $invoice, $plan, $allocation, $billDate, $branchId);
            $this->applyNetworkDeltas($member, $plan, $allocation, $isNew);
            $this->payBillMargin($plan, $invoice, $branchId, $userId, $crossTotal, $member);
            $this->payGmMargin($cart, $invoice, $branchId, $userId, $member);
            $this->deductStock($cart, $branchId, $billDate, $userId);
            $this->createContract($bond, $invoice, $plan, $member, $branchId, $billDate, $grandTotal);

            // Mint the redeemable stock QR now; deliver it (with the contract) after commit.
            $this->pendingQr = app(\App\Services\Qr\RedeemableQrService::class)
                ->forBond($bond, (float) ($data['gold_rate'] ?? 0))->id;

            return $invoice->fresh(['lines', 'customer']);
        });

        $this->deliverPendingQr();

        return $invoice;
    }

    /**
     * Send the just-minted Contract + redeemable QR over WhatsApp, after the sale has
     * committed (HTTP must never run inside the DB transaction). Best-effort and skipped
     * under tests so the suite never hits the live gateway.
     */
    protected function deliverPendingQr(): void
    {
        $qrId = $this->pendingQr;
        $this->pendingQr = null;

        if (! $qrId || app()->runningUnitTests()) {
            return;
        }

        try {
            if ($qr = \App\Models\RedeemableQr::find($qrId)) {
                app(\App\Services\Qr\RedeemableQrService::class)->deliver($qr);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Post-sale QR delivery failed: ' . $e->getMessage());
        }
    }

    /** Create the per-sale contract record (random contract no + per-plan content snapshot). */
    protected function createContract(Bond $bond, SalesInvoice $invoice, Plan $plan, Member $member, int $branchId, string $billDate, float $grandTotal): MemberContract
    {
        $duration = (int) ($plan->validity_months ?: $plan->level_com_duration ?: 12);
        $start = Carbon::parse($billDate);
        $bond->setRelation('plan', $plan);

        return MemberContract::create([
            'contract_no' => $this->nextContractNo(),
            'bond_id' => $bond->id,
            'member_id' => $member->id,
            'plan_id' => $plan->id,
            'branch_id' => $branchId,
            'invoice_no' => $invoice->invoice_no,
            'amount' => $grandTotal,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addMonthsNoOverflow($duration)->toDateString(),
            'content' => app(\App\Services\Contract\ContractContentBuilder::class)->build($bond),
            'status' => 'active',
        ]);
    }

    /** Random, unique contract number, e.g. LJC-7F3A9KQ2. */
    protected function nextContractNo(): string
    {
        do {
            $no = 'LJC-' . strtoupper(Str::random(8));
        } while (MemberContract::where('contract_no', $no)->exists());

        return $no;
    }

    /** Existing member by id, or enrol a new one with KYC + upline/referrer placement. */
    protected function resolveCustomer(array $data, string $billDate, int $branchId): array
    {
        $c = $data['customer'] ?? [];

        if (! empty($c['member_id'])) {
            $member = Member::findOrFail($c['member_id']);
            $this->applyKyc($member, $c);

            return [$member, false];
        }

        $upline = $this->memberByCode($data['upline_code'] ?? null);
        $referrer = $this->memberByCode($data['referrer_code'] ?? null);
        $rankId = Rank::where('depth', 0)->value('id');

        $member = Member::create([
            'member_code' => $this->nextMemberCode(),
            'name' => $c['name'] ?? 'Customer',
            'dob' => $c['dob'] ?? null,
            'father_name' => $c['father_name'] ?? null,
            'address' => $c['address'] ?? null,
            'city' => $c['city'] ?? null,
            'pincode' => $c['pincode'] ?? null,
            'phone' => $c['phone'] ?? '',
            'phone_country_code' => $c['phone_country_code'] ?? '+91',
            'email' => $c['email'] ?? null,
            'pan' => $c['pan'] ?? null,
            'aadhaar' => $c['aadhaar'] ?? null,
            'pan_verified' => ! empty($c['pan_verified']),
            'pan_verified_name' => $c['pan_verified_name'] ?? null,
            'pan_verified_at' => ! empty($c['pan_verified']) ? Carbon::now() : null,
            'aadhaar_verified' => ! empty($c['aadhaar_verified']),
            'aadhaar_verified_at' => ! empty($c['aadhaar_verified']) ? Carbon::now() : null,
            'nominee_name' => $c['nominee_name'] ?? null,
            'nominee_age' => $c['nominee_age'] ?? null,
            'nominee_relation' => $c['nominee_relation'] ?? null,
            'joined_on' => $billDate,
            'upline_id' => $upline?->id,
            'referrer_id' => $referrer?->id,
            'placement' => 'level',
            'rank_id' => $rankId,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
        MemberWallet::firstOrCreate(['member_id' => $member->id]);

        return [$member, true];
    }

    /** Persist a freshly-verified PAN/Aadhaar onto an existing member (never un-verifies). */
    protected function applyKyc(Member $member, array $c): void
    {
        $updates = [];
        if (! empty($c['pan_verified']) && ! $member->pan_verified) {
            $updates += ['pan_verified' => true, 'pan_verified_name' => $c['pan_verified_name'] ?? null, 'pan_verified_at' => Carbon::now()];
        }
        if (! empty($c['aadhaar_verified']) && ! $member->aadhaar_verified) {
            $updates += ['aadhaar_verified' => true, 'aadhaar_verified_at' => Carbon::now()];
        }
        if ($updates) {
            $member->update($updates);
        }
    }

    /** Up to 10 uplines, plan.ic_schedule %, status pending, capped by earning headroom. */
    protected function issueInstantCommission(Member $member, Bond $bond, SalesInvoice $invoice, Plan $plan, float $allocation, string $billDate, int $branchId): int
    {
        $schedule = $plan->ic_schedule ?? [];
        if (empty($schedule)) {
            return 0;
        }

        $issued = 0;
        $upline = $member->upline;

        for ($level = 1; $level <= self::MAX_IC_LEVELS; $level++) {
            if (! $upline) {
                break;
            }
            $pct = (float) ($schedule[$level - 1] ?? 0);
            if ($pct > 0 && $upline->status === 'active') {
                // amount is INR base (allocation is INR), so caps/ranks stay coherent.
                $amount = round($allocation * $pct / 100, 2);
                $cap = $this->earningCap($upline);
                $amount = min($amount, $cap);
                if ($amount > 0) {
                    // Stamp the EARNER's home currency + frozen rate; the wallet pays out
                    // in that currency at this rate, regardless of when it's approved.
                    $earnerBranch = $upline->branch;
                    CommissionLedger::create([
                        'type' => 'IC',
                        'member_id' => $upline->id,
                        'from_member_id' => $member->id,
                        'bond_id' => $bond->id,
                        'invoice_no' => $invoice->invoice_no,
                        'level' => $level,
                        'amount' => $amount,
                        'currency_code' => $earnerBranch?->currency_code ?: 'INR',
                        'fx_rate' => $earnerBranch?->fxRateToBase() ?? 1.0,
                        'status' => 'pending',
                        'earned_on' => $billDate,
                        'branch_id' => $branchId,
                    ]);
                    $issued++;
                }
            }
            $upline = $upline->upline;
        }

        return $issued;
    }

    /**
     * Earning headroom = bonded BV - lifetime earnings (IC + GAP + bill-margin).
     * A member cannot earn more than they have bonded. Returns 0 if inactive/maxed.
     */
    public function earningCap(Member $member): float
    {
        if ($member->status !== 'active') {
            return 0.0;
        }
        $bonded = (float) Bond::where('member_id', $member->id)->where('status', 'active')->sum('value');
        if ($bonded <= 0) {
            return 0.0;
        }
        $earned = (float) CommissionLedger::where('member_id', $member->id)
            ->whereIn('type', ['IC', 'GAP'])->sum('amount');
        $earned += (float) ResellerCommission::where('mapped_uid', $member->member_code)->sum('com_value');

        return max(0.0, round($bonded - $earned, 2));
    }

    /** First installment prorated to the 1st of next month, then monthly. */
    protected function scheduleCbc(Bond $bond, Member $member, float $monthly, int $count, string $billDate): void
    {
        if ($monthly <= 0 || $count <= 0) {
            return;
        }
        $start = Carbon::parse($billDate);
        $firstDue = $start->copy()->addMonthNoOverflow()->startOfMonth();
        $days = min(30, $start->diffInDays($firstDue));
        $firstWorth = round($monthly / 30 * $days, 2);

        // worth is INR base; freeze the earner's currency + rate at schedule time —
        // the SAME stamp CommissionService::issueCbc applies (approval pays at
        // exactly this rate; an unstamped row would default to INR/1.0).
        $earnerBranch = $member->branch_id ? \App\Models\Branch::find($member->branch_id) : null;
        $stamp = [
            'currency_code' => $earnerBranch?->currency_code ?: 'INR',
            'fx_rate' => $earnerBranch?->fxRateToBase() ?? 1.0,
        ];

        CbcEntry::create([
            'bond_id' => $bond->id, 'member_id' => $member->id, 'cbc_date' => $firstDue->toDateString(),
            'code' => $this->cbcCode(), 'worth' => $firstWorth, 'status' => 'pending',
        ] + $stamp);
        for ($i = 1; $i < $count; $i++) {
            CbcEntry::create([
                'bond_id' => $bond->id, 'member_id' => $member->id,
                'cbc_date' => $firstDue->copy()->addMonthsNoOverflow($i)->toDateString(),
                'code' => $this->cbcCode(), 'worth' => $monthly, 'status' => 'pending',
            ] + $stamp);
        }
    }

    /**
     * Incremental network update at billing (replaces the nightly recompute as the live driver):
     * bump the buyer's bv/unpure_bv, roll the same up every ancestor's gbv/unpure_gbv (+downline
     * count for new members), then re-rank the buyer and each ancestor in real time using the
     * same maths as the batch. The scheduled recompute remains an expiry + reconciliation pass.
     */
    protected function applyNetworkDeltas(Member $member, Plan $plan, float $value, bool $isNew): void
    {
        $unpure = round($value * $plan->rankFactor(), 2);

        $member->increment('bv', $value);
        if ($unpure > 0) {
            $member->increment('unpure_bv', $unpure);
        }

        $chain = [];
        $seen = [$member->id => true];   // guard against any accidental upline cycle
        $ancestor = $member->upline;
        while ($ancestor && ! isset($seen[$ancestor->id])) {
            $seen[$ancestor->id] = true;
            $ancestor->increment('gbv', $value);
            if ($unpure > 0) {
                $ancestor->increment('unpure_gbv', $unpure);
            }
            if ($isNew) {
                $ancestor->increment('downline_count');
            }
            $chain[] = $ancestor->id;
            $ancestor = $ancestor->upline;
        }

        $network = app(NetworkService::class);
        $network->evaluateRank($member->id);
        foreach ($chain as $ancestorId) {
            $network->evaluateRank($ancestorId);
        }
    }

    /** Branch billing margin (legacy tbl_reseller_com), skipped for HQ's own bills. */
    protected function payBillMargin(Plan $plan, SalesInvoice $invoice, int $branchId, ?int $userId, float $crossTotal, Member $member): void
    {
        if ($branchId === self::HQ_BRANCH_ID || (float) $plan->billing_margin == 0.0) {
            return;
        }
        $fx = (float) ($invoice->fx_rate ?: 1);
        $marginLocal = round($crossTotal * (float) $plan->billing_margin / 100, 2);   // branch currency
        ResellerCommission::create([
            'bill_date' => $invoice->date,
            'invoice_no' => $invoice->invoice_no,
            'com_type_id' => 1,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'com_value' => round($marginLocal * $fx, 2),   // INR base (caps stay coherent)
            'currency_code' => $invoice->currency_code ?: 'INR',
            'fx_rate' => $fx,
            'reference_member_id' => $member->id,
            'status' => 'passed',
        ]);
        // The branch's own running balance is shown in its operating currency.
        \App\Models\Branch::where('id', $branchId)->increment('bill_margin', $marginLocal);
    }

    /**
     * Gold / Silver (GM) margin — settled INSTANTLY to the billing branch from the metal
     * line-items on the invoice: gold lines → gold_gm_margin, silver lines → silver_gm_margin.
     * Rate is the per-product ₹/gram (catalog_product.gm_margin); earned = weight × rate.
     * Not paid for HQ's own bills (head office is not a reseller). com_type_id 2 = gold, 3 = silver.
     */
    protected function payGmMargin(array $cart, SalesInvoice $invoice, int $branchId, ?int $userId, Member $member): void
    {
        if ($branchId === self::HQ_BRANCH_ID) {
            return;
        }

        $ids = collect($cart)->pluck('catalog_product_id')->filter()->unique();
        if ($ids->isEmpty()) {
            return;
        }
        $products = CatalogProduct::whereIn('id', $ids)->get()->keyBy('id');

        $totals = ['gold' => 0.0, 'silver' => 0.0];
        foreach ($cart as $line) {
            $product = $products->get($line['catalog_product_id'] ?? null);
            if (! $product || ! isset($totals[$product->material])) {
                continue;       // cash / vessel / unknown carry no GM margin
            }
            $weight = (float) ($line['weight'] ?? 0);
            $rate = (float) $product->gm_margin;            // ₹ per gram
            if ($weight > 0 && $rate > 0) {
                $totals[$product->material] += $weight * $rate;
            }
        }

        // gm_margin is a ₹/gram constant, so the computed margin is already INR base.
        $fx = (float) ($invoice->fx_rate ?: 1);
        foreach (['gold' => 2, 'silver' => 3] as $material => $comTypeId) {
            $marginBase = round($totals[$material], 2);   // INR base
            if ($marginBase <= 0) {
                continue;
            }
            ResellerCommission::create([
                'bill_date' => $invoice->date,
                'invoice_no' => $invoice->invoice_no,
                'com_type_id' => $comTypeId,
                'user_id' => $userId,
                'branch_id' => $branchId,
                'com_value' => $marginBase,
                'currency_code' => $invoice->currency_code ?: 'INR',
                'fx_rate' => $fx,
                'reference_member_id' => $member->id,
                'status' => 'passed',
            ]);
            // Branch display balance in its own currency.
            \App\Models\Branch::where('id', $branchId)->increment($material . '_gm_margin', round($marginBase / $fx, 2));
        }
    }

    /** Deduct sold weight/qty from branch stock and log -movements. */
    protected function deductStock(array $cart, int $branchId, string $billDate, ?int $userId): void
    {
        foreach ($cart as $line) {
            if (empty($line['catalog_product_id'])) {
                continue;
            }
            $qty = (float) ($line['weight'] ?? 0);
            $stock = Stock::where('branch_id', $branchId)
                ->where('catalog_product_id', $line['catalog_product_id'])->first();
            if (! $stock) {
                continue;
            }
            $stock->decrement('quantity', $qty);
            StockMovement::create([
                'branch_id' => $branchId,
                'catalog_product_id' => $line['catalog_product_id'],
                'type' => 'sale',
                'qty_change' => -$qty,
                'balance_after' => $stock->fresh()->quantity,
                'ref_type' => 'sale',
                'moved_on' => $billDate,
                'created_by' => $userId,
            ]);
        }
    }

    protected function memberByCode(?string $code): ?Member
    {
        return $code ? Member::where('member_code', strtoupper(trim($code)))->first() : null;
    }

    protected function nextInvoiceNo(): string
    {
        $n = (int) SalesInvoice::max('id') + 1;

        return 'INV-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    protected function nextMemberCode(): string
    {
        $n = (int) Member::max('id') + 1;

        return 'LJW' . str_pad((string) $n, 7, '0', STR_PAD_LEFT);
    }

    protected function cbcCode(): string
    {
        return strtoupper(Str::random(8));
    }
}
