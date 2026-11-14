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
     * Area Distributor branch + login opened by the last bill (complaint 2026-11-11), for the
     * Sales screen to show the login to the biller. Null when the bill opened nothing.
     *
     * @var array{branch_id:int,branch:string,user_id:int,email:string,password:?string,created:bool,member_code:string}|null
     */
    public ?array $openedDealer = null;

    /**
     * @param  array  $data  keys: branch_id, bill_date?, created_by?, plan_id,
     *   payment_type?, payment_reference?, gold_rate?, silver_rate?, diamond_rate?,
     *   referrer_code?, upline_code?, customer{member_id|...kyc}, cart[]
     */
    public function generateInvoice(array $data): SalesInvoice
    {
        $this->openedDealer = null;
        $invoice = DB::transaction(function () use ($data) {
            $branchId = $data['branch_id'];
            $billDate = $data['bill_date'] ?? Carbon::now()->toDateString();
            $userId = $data['created_by'] ?? auth()->id();
            $plan = Plan::findOrFail($data['plan_id']);

            // Customized order (board 2026-08-27): the requesting branch bills the delivered
            // pieces as a G10 material sale at the FROZEN order price — one bill per metal.
            $custom = null;
            $customLineIds = [];
            if (! empty($data['custom_order_id'])) {
                $custom = \App\Models\BranchOrderRequest::with('lines')->findOrFail($data['custom_order_id']);
                abort_unless($custom->isCustomize() && (int) $custom->branch_id === (int) $branchId, 422, 'That customized order does not belong to this branch.');
                abort_unless(in_array($custom->status, ['delivered', 'billed'], true), 422, 'The pieces have not been delivered to this branch yet.');
                $customLineIds = array_values(array_filter(array_map(fn ($l) => (int) ($l['order_line_id'] ?? 0), $data['cart'] ?? [])));
                abort_if(empty($customLineIds), 422, 'Pick the customized order pieces to bill.');
                $materials = $custom->lines->whereIn('id', $customLineIds)->pluck('material')->unique();
                abort_unless($materials->count() === 1, 422, 'Bill one metal per invoice — gold and silver pieces go on separate G10 bills.');
                abort_unless($plan->type === $materials->first(), 422,
                    'Customized ' . $materials->first() . ' pieces must be billed under the G10 ' . $materials->first() . ' plan.');
            }

            // Currency context for this sale: the branch's operating currency and the rate
            // (INR per 1 unit) frozen onto every document so the figures never re-derive.
            // India branch => INR / 1.0, which keeps the maths below identical to before.
            $branch = \App\Models\Branch::find($branchId);
            $fx = $branch?->fxRateToBase() ?? 1.0;
            $currency = $branch?->currency_code ?: 'INR';

            // Board 2026-10-24: a scheme marked "Lord's Branches only" / "Dealers only" cannot
            // be billed at the other kind of branch (the picker hides it; this is the hard gate).
            abort_unless($plan->availableAt($branch), 422, sprintf(
                'Scheme %s is available to %s only; this branch is a %s.',
                $plan->code,
                match ($plan->available_to) { Plan::AVAILABLE_HQ => 'Head Office', Plan::AVAILABLE_BRANCH => "Lord's Branches", default => 'dealers' },
                $branch?->isLordBranch() ? "Lord's Branch" : 'dealer',
            ));

            [$member, $isNew] = $this->resolveCustomer($data, $billDate, $branchId);

            // --- totals from cart (in the BRANCH currency) ---
            $cart = $data['cart'] ?? [];
            $this->assertMetalLinesCarryCharges($cart, $branch);
            $crossTotal = round(array_sum(array_map(fn ($l) => (float) ($l['net_total'] ?? 0), $cart)), 2);
            $cartGrand = round(array_sum(array_map(fn ($l) => (float) ($l['grand_total'] ?? 0), $cart)), 2);
            // Tax follows the branch regime: GST splits the per-line GST the page computed;
            // VAT/none recompute from the taxable base.
            $tax = $branch
                ? $branch->taxOn($crossTotal, $cartGrand - $crossTotal)
                : ['mode' => 'gst', 'total' => round($cartGrand - $crossTotal, 2), 'cgst' => round(($cartGrand - $crossTotal) / 2, 2), 'sgst' => round(($cartGrand - $crossTotal) / 2, 2)];
            $grandTotal = round($crossTotal + $tax['total'], 2);

            $this->assertPlanCaps($plan, $member, $grandTotal, $fx, $billDate);

            // Lord's board 2026-11-13 — Gold Saving joining amounts: PLUS 1 in whole 100 mg
            // pieces at today's price, PLUS 2 in ₹1,500 multiples, PLUS 3 in ₹500 steps.
            $goldSaving = \App\Services\Rd\GoldSavingRules::joining($plan, round($grandTotal * $fx, 2));
            abort_unless($goldSaving['ok'], 422, (string) $goldSaving['message']);

            // Legacy way to bill (user 2026-11-04): every commission — IC, level income, CBC —
            // is a % of the FULL bill value (pre-tax cross total, legacy `billingvalue`); the
            // bond keeps only the legacy allocation share (bvalue = bill × plan.allocation).
            // Both in INR base so caps/ranks stay coherent across currencies.
            $billBase = round($crossTotal * $fx, 2);
            $allocation = $plan->bondValueFor($billBase);
            // Legacy BV / GBV (mybv, mygbv, R/A BV) grow by the GRAND total as billed — tax
            // included — which legacy kept on the bond as epinvalue. Same INR base here.
            $grandBase = round($grandTotal * $fx, 2);

            // --- invoice (amounts stored in the branch currency, stamped with code + rate) ---
            $invoice = SalesInvoice::create([
                'invoice_no' => $this->nextInvoiceNo($branchId),
                'date' => $billDate,
                'customer_member_id' => $member->id,
                'customer_name' => $member->name,
                'buyer_gst' => filled($data['buyer_gst'] ?? null) ? strtoupper(trim($data['buyer_gst'])) : null,
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
            // Legacy: the coupon is bill × cbc % EVERY month for cbc_count months (a ₹1,00,000
            // G5 bill → ₹6,000 × 24), and the five level amounts are bill × level % per month.
            $cbcMonthly = $plan->cbc_count > 0
                ? round($billBase * (float) $plan->cbc_value / 100, 2)
                : 0.0;
            $levelAmounts = array_map(
                fn ($pct) => round($billBase * (float) $pct / 100, 2),
                $plan->level_schedule ?? []
            );

            $bond = Bond::create([
                'member_id' => $member->id,
                'plan_id' => $plan->id,
                'branch_id' => $branchId,
                'bond_date' => $billDate,
                'value' => $allocation,                 // bvalue = allocated amount
                'rd_pieces' => $goldSaving['pieces'],   // Gold Saving: 100 mg pieces fixed at joining
                'invoice_no' => $invoice->invoice_no,
                'lvlcom' => $levelAmounts,
                'upline_member_id' => $member->upline_id,   // level-1 upline frozen at sale (legacy tbl_bond.uplineuserid)
                'cbc_value' => $cbcMonthly,
                'cbc_count' => (int) $plan->cbc_count,
                'cbc_issued' => 0,
                'lvlcom_count' => (int) ($plan->level_com_duration ?: $plan->validity_months),
                'lvlcom_issued' => 0,
                'epin_value' => $grandBase,   // the full bill as billed (legacy epinvalue)
                'mou_id' => $plan->mou_id,
                'status' => 'active',
            ]);

            // Dealership ladder: billing a higher dealership plan promotes the member's
            // existing branch to that plan's level (never downgrades) — board 2026-08-26.
            app(\App\Services\DealershipService::class)->applyPlanLevel($member, $plan);

            $this->scheduleCbc($bond, $member, $cbcMonthly, (int) $plan->cbc_count, $billDate);
            $this->issueInstantCommission($member, $bond, $invoice, $plan, $billBase, $billDate, $branchId);
            // legacy: the buyer's BV and every upline's group BV grow by the bill, not the bond share
            $this->applyNetworkDeltas($member, $plan, $grandBase, $isNew);
            $this->payBillMargin($plan, $invoice, $branchId, $userId, $crossTotal, $member);
            $this->payGmMargin($cart, $invoice, $branchId, $userId, $member);
            $this->deductStock($cart, $branchId, $billDate, $userId, $invoice->id);
            // Legacy parity (complaint 2026-11-11): the Area Distributor scheme opens the
            // dealer's branch + login on the spot and the billed items become its stock.
            $this->openedDealer = app(AreaDistributorService::class)
                ->open($member, $plan, $invoice, $this->cartPieces($cart), $userId);
            if ($custom) {
                app(\App\Services\CustomizeOrderService::class)->onBilled($custom, $customLineIds, $invoice, $userId);
            }

            // Schema-v2 flags: only contract-bearing plans get a MemberContract, and only
            // redeemable plans get a stock QR minted (and delivered after commit).
            // RD 'first_renewal' plans (G11 PLUS2) mint their one-time QR at the first
            // renewal in RdCollectionService — never at billing.
            if ($plan->is_contract) {
                $this->createContract($bond, $invoice, $plan, $member, $branchId, $billDate, $grandTotal);
            }
            if ($plan->is_redeem && $plan->rd_qr_on !== 'first_renewal') {
                $this->pendingQr = app(\App\Services\Qr\RedeemableQrService::class)
                    ->forBond($bond, (float) ($data['gold_rate'] ?? 0))?->id;
            }

            return $invoice->fresh(['lines', 'customer']);
        });

        $this->deliverPendingQr();
        $this->notifyBillingDocs($invoice);
        if ($this->openedDealer && $invoice->customer) {
            app(AreaDistributorService::class)->sendLogin($invoice->customer, $this->openedDealer);
        }
        \App\Services\Activity\ActivityLogger::log('sales', 'billed',
            'Invoice ' . $invoice->invoice_no . ' billed to ' . ($invoice->customer?->member_code ?? $invoice->customer_name) . ' for ₹' . \App\Support\Money::group((float) $invoice->grand_total), [
                'subject' => $invoice, 'details' => ['plan' => $invoice->plan?->code, 'grand_total' => (float) $invoice->grand_total, 'lines' => $invoice->lines->count(), 'payment' => $invoice->payment_type],
            ]);

        return $invoice;
    }

    /**
     * Post-commit billing acknowledgements (board 2026-08-11: push + inbox, WhatsApp
     * is OTP-only): the tax invoice always; the contract for contract-bearing plans
     * that did NOT already deliver it alongside a billing QR.
     */
    protected function notifyBillingDocs(SalesInvoice $invoice): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        try {
            $member = $invoice->customer;
            if (! $member) {
                return;
            }

            \App\Services\Push\Notifier::to($member, 'invoice',
                'Invoice ' . $invoice->invoice_no,
                'Your purchase of ₹' . \App\Support\Money::group((float) $invoice->grand_total) . ' is billed. Thank you for shopping with Lord Jeweller.',
                route: '/invoices/' . $invoice->id,
                data: ['invoice_no' => (string) $invoice->invoice_no],
            );

            $bond = \App\Models\Bond::with('plan')->where('invoice_no', $invoice->invoice_no)->latest('id')->first();
            $qrWentOut = (bool) ($bond && \App\Models\RedeemableQr::where('bond_id', $bond->id)->where('qr_sent', true)->exists());
            if ($bond && $bond->plan?->is_contract && ! $qrWentOut) {
                \App\Services\Push\Notifier::to($member, 'contract',
                    'Your contract ' . $invoice->invoice_no,
                    'Your Lord Jeweller scheme contract is ready. Tap to view.',
                    route: '/contracts/' . $bond->id,
                    data: ['bond_id' => (string) $bond->id, 'contract_url' => app(\App\Services\Contract\ContractService::class)->store($bond)],
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Billing notification failed: ' . $e->getMessage());
        }
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

    /**
     * Serial contract number, e.g. LJC-000010 — matches the invoice-number style
     * (board 2026-08-11: the old random suffix read like a QR code and confused
     * the counter; existing random numbers stay valid, new ones are serial).
     */
    protected function nextContractNo(): string
    {
        $no = 'LJC-' . str_pad((string) ((int) MemberContract::max('id') + 1), 6, '0', STR_PAD_LEFT);

        // Guard against any collision with legacy/random numbers.
        while (MemberContract::where('contract_no', $no)->exists()) {
            $no .= 'A';
        }

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
            'taluka' => $c['taluka'] ?? null,
            'district' => $c['district'] ?? null,
            'state' => $c['state'] ?? null,
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
                // amount is INR base (the bill base is INR), so caps/ranks stay coherent.
                $amount = round($allocation * $pct / 100, 2);
                $cap = $this->earningCap($upline, $billDate);
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
    public function earningCap(Member $member, ?string $onDate = null): float
    {
        // Legacy Trade::checkmemberactive counts only what was booked TODAY (user 2026-11-04:
        // bill the legacy way) — the same rule the monthly engine and the margins already use.
        return app(CommissionService::class)->earningPotential($member, $onDate);
    }

    /**
     * First installment prorated to the 1st of next month, then monthly. Public so
     * `cbc:schedule-missing` gives already-billed bonds the very same schedule.
     */
    public function scheduleCbc(Bond $bond, Member $member, float $monthly, int $count, string $billDate): void
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
    /**
     * Schema-v2 plan ceilings: max_value caps a single bill; max_sales_value caps the
     * customer's total billing on the plan per calendar month. Both are INR-base values,
     * so foreign-branch invoices are converted before comparing.
     */
    protected function assertPlanCaps(Plan $plan, Member $member, float $grandTotal, float $fx, string $billDate): void
    {
        $grandBase = round($grandTotal * $fx, 2);

        if ($plan->max_value !== null && $grandBase > (float) $plan->max_value) {
            throw new \RuntimeException(sprintf(
                'Bill value ₹%s exceeds the %s per-bill limit of ₹%s.',
                \App\Support\Money::group($grandBase), $plan->code, \App\Support\Money::group((float) $plan->max_value)
            ));
        }

        if ($plan->max_sales_value !== null) {
            $month = Carbon::parse($billDate);
            $billed = (float) SalesInvoice::where('customer_member_id', $member->id)
                ->where('plan_id', $plan->id)
                ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                ->selectRaw('COALESCE(SUM(grand_total * COALESCE(fx_rate, 1)), 0) AS billed')
                ->value('billed');

            if ($billed + $grandBase > (float) $plan->max_sales_value) {
                throw new \RuntimeException(sprintf(
                    'Monthly billing cap reached for %s: ₹%s already billed this month, cap is ₹%s.',
                    $plan->code, \App\Support\Money::group($billed), \App\Support\Money::group((float) $plan->max_sales_value)
                ));
            }
        }
    }

    protected function applyNetworkDeltas(Member $member, Plan $plan, float $value, bool $isNew): void
    {
        // $value is the FULL bill as billed, tax included (legacy mybv/mygbv += invoicegrandtotal,
        // stored on the bond as epin_value); the rank % is taken off the same bill.
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

    /**
     * Hard gate (2026-11-14): a gold / silver stock line can never be billed below
     * weight × rate + its making and wastage, nor — in a GST branch — without its GST.
     *
     * Live bill INV-B200-0110 was saved at the bare metal price (1 g gold ₹13,210 instead of
     * ₹14,286.62) because the screen lost the charges on the way; whoever calls this service,
     * such a bill is refused now. Charges = CatalogProduct::charges() (product %, else the Charge
     * Brackets slab). A customized-order piece is exempt: its price is frozen on the order line.
     */
    protected function assertMetalLinesCarryCharges(array $cart, ?\App\Models\Branch $branch): void
    {
        $gstBranch = ! in_array($branch?->tax_regime, ['vat', 'none'], true);
        foreach ($cart as $line) {
            if (empty($line['catalog_product_id']) || ! empty($line['order_line_id'])) {
                continue;
            }
            $cp = CatalogProduct::find($line['catalog_product_id']);
            if (! $cp || ! in_array($cp->material, ['gold', 'silver'], true) || $cp->is_custom_order) {
                continue;
            }
            $weight = (float) ($line['weight'] ?? 0);
            $base = round($weight * (float) ($line['rate'] ?? 0), 2);
            if ($base <= 0) {
                continue;
            }
            $name = \App\Support\Translatable::pick($cp->name) ?: $cp->code;
            $charges = $cp->charges($weight);
            abort_if($charges['source'] === 'none', 422,
                "\"{$name}\" has no making / wastage charge on the product and no Charge Bracket for its weight — set it before billing.");

            $net = (float) ($line['net_total'] ?? 0);
            $minNet = round($base * (1 + ($charges['making_pct'] + $charges['wastage_pct']) / 100), 2);
            abort_if($net + 1 < $minNet, 422, sprintf(
                'Bill refused: "%s" is billed at ₹%s but its metal value with making and wastage is ₹%s. Reload the Sales page and add the item again.',
                $name, \App\Support\Money::group($net), \App\Support\Money::group($minNet)));

            if ($gstBranch && (float) $cp->gst_pct > 0) {
                $minGst = round($net * (float) $cp->gst_pct / 100, 2);
                abort_if((float) ($line['grand_total'] ?? 0) - $net + 1 < $minGst, 422, sprintf(
                    'Bill refused: "%s" carries no GST (₹%s due). Reload the Sales page and add the item again.',
                    $name, \App\Support\Money::group($minGst)));
            }
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

        // EP filter (board 2026-08-11): the margin's beneficiary is the BRANCH's
        // distributor — cap at their remaining earning potential, skip when exhausted.
        $beneficiary = \App\Models\Branch::find($branchId)?->distributorUser?->memberAccount;
        $marginBase = app(CommissionService::class)
            ->capByEp($beneficiary, round($marginLocal * $fx, 2), (string) $invoice->date);
        if ($marginBase <= 0) {
            return;
        }
        $marginLocal = round($marginBase / $fx, 2);

        ResellerCommission::create([
            'bill_date' => $invoice->date,
            'invoice_no' => $invoice->invoice_no,
            'com_type_id' => ResellerCommission::COM_BILL_MARGIN,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'com_value' => $marginBase,   // INR base (caps stay coherent)
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
        $beneficiary = \App\Models\Branch::find($branchId)?->distributorUser?->memberAccount;
        foreach (['gold' => ResellerCommission::COM_GOLD_MARGIN, 'silver' => ResellerCommission::COM_SILVER_MARGIN] as $material => $comTypeId) {
            // EP filter (board 2026-08-11) — capped at the branch distributor's headroom
            $marginBase = app(CommissionService::class)
                ->capByEp($beneficiary, round($totals[$material], 2), (string) $invoice->date);
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

    /**
     * Deduct sold PIECES from branch stock and log -movements. stock.quantity is a
     * piece count (cash = ₹), so a sale of 2 × 100 mg coins deducts 2 — never 0.2.
     */
    /** Pieces a cart line sells (cash = ₹): the bill's own piece count, else weight → pieces. */
    protected function linePieces(array $line): float
    {
        $cp = CatalogProduct::find($line['catalog_product_id']);

        return isset($line['qty']) && $line['qty'] !== null && ($line['material'] ?? $cp?->material) !== 'cash'
            ? (float) $line['qty']                                             // pieces straight from the bill
            : (float) ($cp?->piecesFromWeight((float) ($line['weight'] ?? 0)) ?? ($line['weight'] ?? 0));
    }

    /** catalog_product_id => pieces billed (ordinary lines only — custom-order pieces are excluded). */
    protected function cartPieces(array $cart): array
    {
        $out = [];
        foreach ($cart as $line) {
            if (empty($line['catalog_product_id']) || ! empty($line['order_line_id'])) {
                continue;
            }
            $out[$line['catalog_product_id']] = ($out[$line['catalog_product_id']] ?? 0) + $this->linePieces($line);
        }

        return $out;
    }

    protected function deductStock(array $cart, int $branchId, string $billDate, ?int $userId, ?int $invoiceId = null): void
    {
        foreach ($cart as $line) {
            if (empty($line['catalog_product_id'])) {
                continue;
            }
            $qty = $this->linePieces($line);
            // Custom pieces are keyed by their order line (board 2026-08-27); ordinary rows = 0.
            $stock = Stock::where('branch_id', $branchId)
                ->where('catalog_product_id', $line['catalog_product_id'])
                ->where('order_line_id', (int) ($line['order_line_id'] ?? 0))->first();
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
                'ref_id' => $invoiceId,   // lets a cancellation restore exactly these pieces
                'moved_on' => $billDate,
                'created_by' => $userId,
            ]);
        }
    }

    protected function memberByCode(?string $code): ?Member
    {
        return $code ? Member::where('member_code', strtoupper(trim($code)))->first() : null;
    }

    /**
     * Per-branch billing serial (board 2026-08-11, legacy parity): every branch —
     * HQ included — runs its own series, e.g. INV-HQ-0001 / INV-B210-0004. The
     * prefix comes from branches.invoice_prefix (editable in Master → Branches).
     */
    protected function nextInvoiceNo(?int $branchId = null): string
    {
        $branch = $branchId ? \App\Models\Branch::find($branchId) : null;
        $prefix = $branch?->invoice_prefix ?: ($branchId ? 'B' . $branchId : 'X');

        $n = (int) SalesInvoice::where('branch_id', $branchId)->count() + 1;
        do {
            $no = 'INV-' . strtoupper($prefix) . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (SalesInvoice::where('invoice_no', $no)->exists());

        return $no;
    }

    protected function nextMemberCode(): string
    {
        // withTrashed: a member deleted with a cancelled bill keeps its code (2026-11-11) —
        // the next enrolment must not be handed the same number.
        $n = (int) Member::withTrashed()->max('id') + 1;
        do {
            $code = 'LJW' . str_pad((string) $n++, 7, '0', STR_PAD_LEFT);
        } while (Member::withTrashed()->where('member_code', $code)->exists());

        return $code;
    }

    protected function cbcCode(): string
    {
        return strtoupper(Str::random(8));
    }
}
