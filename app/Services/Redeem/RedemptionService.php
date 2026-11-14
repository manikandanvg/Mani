<?php

namespace App\Services\Redeem;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\RedeemableQr;
use App\Models\RedemptionInvoice;
use App\Models\RedemptionLine;
use App\Models\ResellerCommission;
use App\Models\SaleLine;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Stock-QR redemption — the ACTUAL sale (legacy admin/trade/redeeminvqr). Two modes:
 *
 *  A) Metal-purchase plans (type gold/silver — G10 Gold P206, G10 Silver P211): the metal was
 *     billed on the original sale, so the cart is rebuilt from that sale's lines, it can be
 *     redeemed ONLY at the billing branch, no stock is moved, and NO margin is paid (already
 *     earned at billing).
 *
 *  B) Every other redeemable bond: the operator picks stock to the QR's worth, redeemable at
 *     ANY branch; the carted items are deducted from the redeeming branch's stock and the branch
 *     earns the gold/silver GM margin (no bill margin). Plans flagged `useraccess` may turn the
 *     customer into a dealer (branch + distributor) seeded with the redeemed stock.
 *
 * Pricing: rate + catalog making/wastage/hallmark is the billed value, GST added ON TOP of it (board 2026-11-14; user
 * 2026-11-13) — no cash→gold reversal. A past-dated (legacy) QR prices its metal at the frozen
 * rate of its own date, on the redeem screen and on the invoice alike (rateFor()).
 */
class RedemptionService
{
    public const DEALER_DEFAULT_PASSWORD = '123456';

    /** GST halves (CGST + SGST). */
    public const GST_SPLIT = 2;

    /** Mode A = the metal itself was billed up front (plans typed gold/silver). */
    public function isMetalPurchasePlan($plan): bool
    {
        return $plan && in_array($plan->type, ['gold', 'silver'], true);
    }

    /** Dealer-account opening at redeem is driven by the plan's `useraccess` flag (schema v2). */
    public function dealerEligible($plan): bool
    {
        return (bool) ($plan?->useraccess);
    }

    /**
     * Fetch a pending QR and everything the redeem screen needs. $currentBranchId enforces
     * the Mode-A "billing branch only" rule when provided.
     *
     * @return array{qr:RedeemableQr,bond:?\App\Models\Bond,plan:mixed,member:?Member,mode:string,branch_locked:bool,worth:float,cart:array}
     */
    public function lookup(string $qrCode, ?int $currentBranchId = null): array
    {
        $qr = RedeemableQr::where('qr_code', $qrCode)->where('status', 'pending')->first();
        abort_unless($qr, 422, 'Invalid QR code or already redeemed.');

        $bond = $qr->bond()->with('plan')->first();
        $plan = $bond?->plan;
        $member = $qr->member;
        // Legacy QRs (board 2026-11-01): any branch, at the metal price of the QR's own date.
        $legacy = $qr->source === RedeemableQr::SOURCE_LEGACY;
        $modeA = $this->isMetalPurchasePlan($plan) && ! $legacy;

        if ($modeA && $currentBranchId !== null) {
            abort_unless((int) $currentBranchId === (int) $bond->branch_id, 422,
                'This plan can be redeemed only at the billing branch.');
        }

        return [
            'qr' => $qr,
            'bond' => $bond,
            'plan' => $plan,
            'member' => $member,
            'mode' => $modeA ? 'A' : 'B',
            'branch_locked' => $modeA,
            'dealer_eligible' => $this->dealerEligible($plan),
            'worth' => (float) $qr->cash_worth,
            'frozen_rate' => (float) $qr->frozen_rate ?: null,
            'priced_on' => $qr->priced_on,
            'cart' => $modeA ? $this->cartFromSale($bond) : [],
        ];
    }

    /** Mode A cart = the metal lines from the original sale (by invoice_no). */
    protected function cartFromSale($bond): array
    {
        if (! $bond) {
            return [];
        }

        return SaleLine::with('catalogProduct')
            ->whereHas('invoice', fn ($q) => $q->where('invoice_no', $bond->invoice_no))
            ->get()
            ->map(fn (SaleLine $l) => $this->normaliseLine(
                $l->catalogProduct,
                // the catalog name; a sale line that only carries the bare metal ("gold") gets it too
                description: $l->catalogProduct && (blank($l->description) || strcasecmp(trim((string) $l->description), (string) $l->catalogProduct->material) === 0)
                    ? self::lineLabel($l->catalogProduct, 0)
                    : (string) ($l->description ?: 'ITEM'),
                quantity: (float) $l->qty,
                rate: (float) $l->rate,
                making: (float) $l->making,
                wastage: (float) $l->wastage,
            ))
            ->all();
    }

    /** What a QR may be redeemed for (board 2026-11-14) — cash stock is never redeemable. */
    public const REDEEMABLE_MATERIALS = ['gold', 'silver'];

    /** Price one Mode-B line from a catalog product at the live rate (a normal sale). */
    public function priceLine(CatalogProduct $cp, float $unitWeight, float $quantity, ?LiveRate $rate = null): array
    {
        $rate ??= $this->liveRate();
        $perGram = $cp->material === 'silver' ? (float) ($rate->silver ?? 0) : (float) ($rate->gold ?? 0);
        $ratePerPiece = $cp->material === 'vessel' ? $unitWeight : round($unitWeight * $perGram, 2);
        $amount = round($quantity * $ratePerPiece, 2);
        // the product's own making + wastage; the Charge Brackets slab when it carries none —
        // the same rule as billing (CatalogProduct::charges). Never a metal item with no charge.
        $charges = $cp->charges($unitWeight);
        abort_if($cp->lacksCharges($unitWeight), 422,
            '"' . self::lineLabel($cp) . '" has no making / wastage charge on the product and no Charge Bracket for its weight — set it before redeeming.');
        $making = round($amount * $charges['making_pct'] / 100, 2);
        $wastage = round($amount * $charges['wastage_pct'] / 100, 2);

        return $this->normaliseLine($cp,
            description: $this->lineLabel($cp, $unitWeight),
            quantity: $quantity, rate: $ratePerPiece, making: $making, wastage: $wastage,
            unitWeight: $unitWeight, amount: $amount,
        );
    }

    /**
     * A uniform internal line shape used by both modes.
     *
     * GST GOES ON TOP of the row (Lord's board 2026-11-14, invoice RDM-B1978-0042): rate +
     * making + wastage is the row value (taxable) and the 3 % GST is added to it — the way a QR's
     * worth is built. ₹2,01,836.26 of goods → GST 6,055.09 → grand ₹2,07,891.35.
     * (13 to 14 Nov 2026 the GST was taken OUT of the row value instead; the invoices raised then
     * keep their figures — line_total there equals amount + making + wastage.)
     */
    protected function normaliseLine(?CatalogProduct $cp, string $description, float $quantity, float $rate, float $making = 0, float $wastage = 0, float $unitWeight = 0, ?float $amount = null): array
    {
        $amount ??= round($quantity * $rate, 2);
        $hallmark = round((float) ($cp->hallmark_charge ?? 0) * $quantity, 2);
        $gstPct = (float) ($cp->gst_pct ?? 3);
        $taxable = round($amount + $making + $wastage + $hallmark, 2);
        $gst = round($taxable * $gstPct / 100, 2);

        return [
            'catalog_product_id' => $cp?->id,
            'description' => $description,
            'hsn_code' => $cp?->hsn_code,
            'material' => $cp?->material,
            'unit_weight' => $unitWeight,
            'quantity' => $quantity,
            'rate' => $rate,
            'making' => round($making + $hallmark, 2),
            'wastage' => $wastage,
            'gst_pct' => $gstPct,
            'amount' => $amount,
            'taxable' => $taxable,
            'gst' => $gst,
            'line_total' => round($taxable + $gst, 2),
        ];
    }

    /**
     * "Description of Goods" = the Catalog Product name in capitals ("100 mg Gold" in the
     * catalog prints "100 MG GOLD" — user 2026-11-13). The old label built the weight itself and
     * trimmed the zeros off whole milligrams: 100 mg printed as "1 MG GOLD", 500 mg as "5 MG GOLD".
     * Nothing is added to the catalog string — the weight has its own column on the invoice.
     */
    public static function lineLabel(CatalogProduct $cp, float $unitWeight = 0): string
    {
        $name = trim((string) \App\Support\Translatable::pick($cp->name, \App\Support\Translatable::defaultLocale()))
            ?: (string) $cp->material;

        return mb_strtoupper($name);
    }

    /** Store + return a fresh OTP for the QR (the caller sends it over WhatsApp). */
    public function generateOtp(RedeemableQr $qr): string
    {
        $otp = (string) random_int(10000, 99999);
        $qr->update(['otp' => $otp, 'otp_sent_at' => Carbon::now()]);

        return $otp;
    }

    public function verifyOtp(RedeemableQr $qr, ?string $otp): bool
    {
        return filled($otp) && hash_equals((string) $qr->otp, (string) $otp);
    }

    /**
     * Finalise a redemption.
     *
     * @param  array  $data  keys: qr_code, branch_id (redeeming), otp,
     *   lines[] (Mode B: [{catalog_product_id, unit_weight, quantity}]),
     *   create_dealer?, dealer{branch_name, gst_no}?, created_by?
     */
    public function redeem(array $data): RedemptionInvoice
    {
        $invoice = DB::transaction(function () use ($data) {
            $branchId = (int) $data['branch_id'];
            $ctx = $this->lookup($data['qr_code'], $branchId);
            /** @var RedeemableQr $qr */
            $qr = $ctx['qr'];
            $plan = $ctx['plan'];
            $member = $ctx['member'];
            $modeA = $ctx['mode'] === 'A';

            abort_unless($this->verifyOtp($qr, $data['otp'] ?? null), 422, 'Invalid or missing OTP.');

            // Currency context: the redeeming branch's currency and its INR rate frozen
            // onto the invoice (same policy as SalesService — India = INR/1.0, unchanged).
            $branch = Branch::find($branchId);
            $fx = $branch?->fxRateToBase() ?? 1.0;
            $currency = $branch?->currency_code ?: 'INR';

            // Build the priced lines — at the branch REGION's metal rate (quoted in
            // the branch currency), so a foreign redemption prices in its own market.
            // Fresh query on purpose: LiveRate::latestFor's static memo outlives a
            // request only in long-lived processes (tests) and would serve stale rows.
            // rateFor() is the ONE rate source — the redeem screen carts with it too, so the
            // invoice is exactly the cart the operator saw (user 2026-11-13, invoice 4598).
            $rate = $this->rateFor($qr, $country = $branch?->country ?: 'IN');
            $market = $this->liveRate($country);
            // Mode B prices from this feed — refuse loudly when the region has no
            // rate row (the zero-rate stub would price every line at 0 and fail the
            // worth gate with a misleading "below the QR worth" message).
            abort_if($ctx['mode'] === 'B' && ! $rate->exists, 422,
                "No live metal rate configured for region {$country} — set it before redeeming.");
            $lines = $modeA
                ? $ctx['cart']
                : collect($data['lines'] ?? [])
                    ->filter(fn ($l) => ! empty($l['catalog_product_id']) && (float) ($l['quantity'] ?? 0) > 0)
                    ->map(fn ($l) => $this->priceLine(
                        CatalogProduct::findOrFail($l['catalog_product_id']),
                        (float) ($l['unit_weight'] ?? 0), (float) $l['quantity'], $rate))
                    ->all();
            abort_if(empty($lines), 422, 'Add at least one item to redeem.');
            // Board 2026-11-14: a QR is redeemed for GOLD and SILVER items only — never cash (or any
            // other stock), at every branch, Head Office included.
            // (Mode A is the customer's own gold / silver purchase bill — nothing is picked there.)
            foreach ($modeA ? [] : $lines as $l) {
                abort_unless(in_array($l['material'] ?? null, self::REDEEMABLE_MATERIALS, true), 422,
                    'A QR can be redeemed for gold and silver items only — "' . ($l['description'] ?? 'this item') . '" is not allowed.');
            }

            $taxable = round(array_sum(array_column($lines, 'taxable')), 2);
            $gst = round(array_sum(array_column($lines, 'gst')), 2);
            // Tax follows the branch regime (GST split / flat VAT / none).
            $tax = $branch
                ? $branch->taxOn($taxable, $gst)
                : ['mode' => 'gst', 'total' => $gst, 'cgst' => round($gst / self::GST_SPLIT, 2), 'sgst' => round($gst / self::GST_SPLIT, 2)];
            $grand = round($taxable + $tax['total'], 2);

            // Worth gate (Mode B): the invoice must cover at least the QR's worth.
            // cash_worth is INR base; compare in the branch currency at the frozen rate.
            if (! $modeA) {
                $sym = $branch?->currencySymbol() ?: '₹';
                $worthLocal = round((float) $qr->cash_worth / $fx, 2);
                abort_if($grand + 0.01 < $worthLocal, 422,
                    'Redeemed value (' . $sym . \App\Support\Money::group($grand) . ') is below the QR worth (' . $sym . \App\Support\Money::group($worthLocal) . ').');
            }

            $invoice = RedemptionInvoice::create([
                'invoice_no' => $this->nextInvoiceNo($branchId),
                'invoice_date' => Carbon::now()->toDateString(),
                'redeemable_qr_id' => $qr->id,
                'bond_id' => $qr->bond_id,
                'member_id' => $qr->member_id,
                'branch_id' => $branchId,
                'reference_no' => $member?->member_code ?? $qr->invoice_no,
                'referrer_name' => $member?->upline?->name,
                'buyer_gst' => $buyerGst = $this->resolveBuyerGst($member, $data['buyer_gst'] ?? null),
                'payment_mode' => 'pending',
                'payment_reference' => $qr->qr_code,
                // header of the bill = the LIVE market price of the redemption day (as legacy printed it);
                // the calculation uses the frozen (billed-date) prices, stamped alongside
                'gold_rate' => (float) ($market->gold ?? 0),
                'silver_rate' => (float) ($market->silver ?? 0),
                'frozen_gold_rate' => (float) ($rate->gold ?? 0) ?: null,
                'frozen_silver_rate' => (float) ($rate->silver ?? 0) ?: null,
                'frozen_on' => $this->billedRates($qr, $market)['date'],
                'taxable_total' => $taxable,
                'cgst' => $tax['cgst'],
                'sgst' => $tax['sgst'],
                'tax_regime' => $tax['mode'],
                'tax_total' => $tax['total'],
                'grand_total' => $grand,
                'currency_code' => $currency,
                'fx_rate' => $fx,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            foreach ($lines as $l) {
                RedemptionLine::create([
                    'redemption_invoice_id' => $invoice->id,
                    'catalog_product_id' => $l['catalog_product_id'],
                    'description' => $l['description'],
                    'hsn_code' => $l['hsn_code'],
                    'material' => $l['material'],
                    'unit_weight' => $l['unit_weight'],
                    'quantity' => $l['quantity'],
                    'rate' => $l['rate'],
                    'making' => $l['making'],
                    'wastage' => $l['wastage'],
                    'gst_pct' => $l['gst_pct'],
                    'amount' => $l['amount'],
                    'line_total' => $l['line_total'],
                ]);
            }

            // Mode B only: move stock out of the redeeming branch + pay the GM margin.
            if (! $modeA) {
                $this->deductStock($lines, $branchId, $invoice);
                $this->payGmMargin($lines, $branchId, $invoice, $member);
            }

            // Optional: turn the customer into a dealer seeded with the redeemed stock.
            // The dealer branch inherits the same GSTIN resolved for the buyer.
            if (! empty($data['create_dealer']) && $ctx['dealer_eligible'] && $member) {
                $dealer = $data['dealer'] ?? [];
                $dealer['gst_no'] = $buyerGst;
                $this->openDealerAccount($member, $lines, $dealer, $invoice);
            }

            $qr->update([
                'status' => 'redeemed',
                'redeem_branch_id' => $branchId,
                'redeemed_at' => Carbon::now(),
            ]);

            return $invoice->fresh('lines');
        });

        // Redemption acknowledgement (board 2026-08-11: push + inbox) — post-commit.
        if (! app()->runningUnitTests()) {
            \App\Services\Push\Notifier::to($invoice->member, 'redeem',
                'Gold redeemed — ' . $invoice->invoice_no,
                'Your QR was redeemed at ' . ($invoice->branch?->name ?? 'the branch')
                    . ' for ₹' . \App\Support\Money::group((float) $invoice->grand_total) . '. Tax invoice attached in the app.',
                route: '/redemptions/' . $invoice->id,
                data: ['invoice_no' => (string) $invoice->invoice_no],
            );
        }

        return $invoice;
    }

    /** Deduct each line's total weight/qty from the redeeming branch's stock. */
    protected function deductStock(array $lines, int $branchId, RedemptionInvoice $invoice): void
    {
        foreach ($lines as $l) {
            if (! $l['catalog_product_id']) {
                continue;
            }
            // stock.quantity = PIECES for every material (board 2026-08-10) — a line of
            // 2 × 100 mg coins moves 2, not 0.2 grams.
            $qty = (float) $l['quantity'];
            if ($qty <= 0) {
                continue;
            }
            // Redeem consumes the REDEEMING branch's own stock only — never HQ/admin
            // stock, and never below zero.
            $stock = Stock::where('branch_id', $branchId)
                ->where('catalog_product_id', $l['catalog_product_id'])
                ->lockForUpdate()->first();
            abort_if(! $stock || (float) $stock->quantity + 1e-6 < $qty, 422,
                'Insufficient branch stock for ' . $l['description'] . ' — redemption uses the redeeming branch\'s own stock.');
            $stock->decrement('quantity', $qty);
            StockMovement::create([
                'branch_id' => $branchId,
                'catalog_product_id' => $l['catalog_product_id'],
                'type' => 'sale',
                'qty_change' => -$qty,
                'balance_after' => $stock->fresh()->quantity,
                'ref_type' => 'redemption',
                'ref_id' => $invoice->id,
                'moved_on' => $invoice->invoice_date,
                'created_by' => $invoice->created_by,
            ]);
        }
    }

    /** Gold/silver GM margin to the redeeming branch (no bill margin), per metal weight. */
    protected function payGmMargin(array $lines, int $branchId, RedemptionInvoice $invoice, ?Member $member): void
    {
        $totals = ['gold' => 0.0, 'silver' => 0.0];
        foreach ($lines as $l) {
            if (! isset($totals[$l['material']]) || ! $l['catalog_product_id']) {
                continue;
            }
            $cp = CatalogProduct::find($l['catalog_product_id']);
            $weight = (float) $l['quantity'] * (float) $l['unit_weight'];
            $totals[$l['material']] += $weight * (float) ($cp->gm_margin ?? 0);
        }

        // gm_margin is a ₹/gram constant → the margin is INR base (same as SalesService);
        // com_value stays INR base for coherent caps, the branch balance shows its own currency.
        $fx = (float) ($invoice->fx_rate ?: 1);
        $beneficiary = Branch::find($branchId)?->distributorUser?->memberAccount;
        foreach (['gold' => ResellerCommission::COM_GOLD_MARGIN, 'silver' => ResellerCommission::COM_SILVER_MARGIN] as $material => $comType) {
            // EP filter (board 2026-08-11) — capped at the branch distributor's headroom
            $margin = app(\App\Services\CommissionService::class)
                ->capByEp($beneficiary, round($totals[$material], 2), (string) $invoice->invoice_date);
            if ($margin <= 0) {
                continue;
            }
            ResellerCommission::create([
                'bill_date' => $invoice->invoice_date,
                'invoice_no' => $invoice->invoice_no,
                'com_type_id' => $comType,
                'user_id' => $invoice->created_by,
                'branch_id' => $branchId,
                'com_value' => $margin,
                'currency_code' => $invoice->currency_code ?: 'INR',
                'fx_rate' => $fx,
                'reference_member_id' => $member?->id,
                'status' => 'passed',
            ]);
            Branch::where('id', $branchId)->increment($material . '_gm_margin', round($margin / $fx, 2));
        }
    }

    /**
     * Turn the member into a dealer: create a branch + distributor login (default password)
     * and seed it with the redeemed stock. Idempotent — if the member already has a
     * distributor login/branch, reuse it and just add the stock.
     */
    protected function openDealerAccount(Member $member, array $lines, array $dealer, RedemptionInvoice $invoice): void
    {
        $existing = User::where('member_code', $member->member_code)
            ->whereNotNull('branch_id')->first();

        if ($existing) {
            $branchId = $existing->branch_id;
        } else {
            // Level = the dealership plan's rung on the ladder (board 2026-08-26); a
            // non-dealership plan still opens a plain Retailer branch.
            $planLevel = app(\App\Services\DealershipService::class)->levelForPlan($invoice->bond?->plan);
            $branch = Branch::create([
                'name' => $dealer['branch_name'] ?? ($member->name . ' — Dealer'),
                'city' => $member->city,
                'address' => $member->address,
                'phone' => $member->phone,
                'gst_no' => $dealer['gst_no'] ?? null,
                'level' => $planLevel ?? 'reseller',
                'is_active' => true,
            ]);
            $branchId = $branch->id;

            $user = User::create([
                'name' => $member->name,
                'email' => strtolower($member->member_code) . '@lordicl',
                'password' => Hash::make(self::DEALER_DEFAULT_PASSWORD),
                'status' => 'active',
                'branch_id' => $branchId,
                'member_code' => $member->member_code,
            ]);
            $user->assignRole('distributor');
            $member->update(['branch_id' => $branchId]);
            $invoice->update(['dealer_created' => true]);
        }

        // Seed the redeemed items as the dealer branch's stock (catalog items already exist).
        foreach ($lines as $l) {
            if (! $l['catalog_product_id']) {
                continue;
            }
            // stock.quantity = PIECES for every material (board 2026-08-10) — a line of
            // 2 × 100 mg coins moves 2, not 0.2 grams.
            $qty = (float) $l['quantity'];
            $stock = Stock::firstOrCreate(
                ['branch_id' => $branchId, 'catalog_product_id' => $l['catalog_product_id']],
                ['quantity' => 0],
            );
            $stock->increment('quantity', $qty);
        }
    }

    /** Normalise a GSTIN input: trim/upper, and treat blanks or "NA"/"N/A" as null. */
    protected function cleanGst($gst): ?string
    {
        $g = strtoupper(trim((string) $gst));

        return ($g === '' || in_array($g, ['NA', 'N/A', '-NA-', '-'], true)) ? null : $g;
    }

    /**
     * The buyer's GSTIN for the invoice: prefer one already on record (this member's
     * existing dealer branch), otherwise use whatever the operator typed at redeem.
     */
    protected function resolveBuyerGst(?Member $member, $typed): ?string
    {
        if ($member) {
            $onRecord = User::where('member_code', $member->member_code)
                ->whereNotNull('branch_id')->with('branch')->first()?->branch?->gst_no;
            if ($g = $this->cleanGst($onRecord)) {
                return $g;
            }
        }

        return $this->cleanGst($typed);
    }

    /**
     * The metal rates a QR is carted AND billed at: the live regional feed with gold and silver
     * replaced by the QR's FROZEN prices — those of its billed date (billedRates()). The redeem screen and redeem() must both price from here — when the screen
     * carted at today's rate and the invoice billed at the frozen one, a ₹4,00,085 cart came out
     * as a ₹4,10,245.86 invoice (user 2026-11-13).
     */
    public function rateFor(?RedeemableQr $qr, string $country = 'IN'): LiveRate
    {
        $rate = $this->liveRate($country);
        $billed = $this->billedRates($qr, $rate);
        if ($billed['gold'] <= 0 && $billed['silver'] <= 0) {
            return $rate;
        }
        $r = $rate->replicate();
        $r->exists = true;
        foreach (['gold', 'silver'] as $metal) {
            if ($billed[$metal] > 0) {
                $r->{$metal} = $billed[$metal];
            }
        }

        return $r;
    }

    /**
     * The FROZEN price of a QR = the gold AND the silver price of the day it was BILLED (final
     * confirmation, user 2026-11-13 — legacy priced 569 of 606 later-day redemptions that way):
     * a gold item is priced at that day's gold rate, a silver item at that day's silver rate,
     * whatever the QR's own metal. Source: the rates stamped on the QR's original bill
     * (sales_invoices.gold_rate / silver_rate); the QR's own stored rate wins for its own metal.
     * 0 = not known for that metal → the live rate is used.
     *
     * Legacy silver QRs were staged with the GOLD rate as their frozen rate (₹15,465/g on a
     * silver QR) — a stored silver rate that is really the bill's gold rate is ignored here.
     *
     * @return array{gold:float,silver:float,date:?string}
     */
    public function billedRates(?RedeemableQr $qr, ?LiveRate $live = null): array
    {
        $out = ['gold' => 0.0, 'silver' => 0.0, 'date' => null];
        if (! $qr) {
            return $out;
        }
        $sale = filled($qr->invoice_no)
            ? \App\Models\SalesInvoice::where('invoice_no', $qr->invoice_no)->first(['date', 'gold_rate', 'silver_rate'])
            : null;
        $out['gold'] = (float) ($sale->gold_rate ?? 0);
        $out['silver'] = (float) ($sale->silver_rate ?? 0);
        // a QR priced on its own day (Gold Saving renewal / settlement) shows that day
        $date = ((float) $qr->frozen_rate > 0 && $qr->priced_on) ? $qr->priced_on : ($sale->date ?? $qr->priced_on);
        $out['date'] = $date ? Carbon::parse($date)->toDateString() : null;

        $own = (float) $qr->frozen_rate;
        if ($own > 0) {
            $metal = $qr->qr_mode === 'silver' ? 'silver' : 'gold';
            $reallyGold = $metal === 'silver' && (
                ($sale && abs($own - (float) $sale->gold_rate) < 1)
                || (! $sale && (float) ($live->silver ?? 0) > 0 && $own > 10 * (float) $live->silver)
            );
            if ($reallyGold) {
                $out['gold'] = $out['gold'] ?: $own;
            } else {
                $out[$metal] = $own;
            }
        }

        return $out;
    }

    protected function liveRate(string $country = 'IN'): LiveRate
    {
        return LiveRate::where('country', $country)->latest('effective_at')->first()
            ?? new LiveRate(['gold' => 0, 'silver' => 0]);
    }

    /** Per-branch redemption serial (board 2026-08-11) — same scheme as billing. */
    protected function nextInvoiceNo(?int $branchId = null): string
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        $prefix = $branch?->invoice_prefix ?: ($branchId ? 'B' . $branchId : 'X');

        $n = (int) RedemptionInvoice::where('branch_id', $branchId)->count() + 1;
        do {
            $no = 'RDM-' . strtoupper($prefix) . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (RedemptionInvoice::where('invoice_no', $no)->exists());

        return $no;
    }
}
