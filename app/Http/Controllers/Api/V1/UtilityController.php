<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\ChargeBracket;
use App\Models\LiveRate;
use App\Models\Plan;
use App\Support\Translatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home-screen utilities (board 2026-08-11 items 8.3–8.5, legacy web-view parity):
 * Business Calculator, live Price List, and the Store Locator. All public — the
 * legacy app showed these pre-login as the sales funnel.
 */
class UtilityController extends Controller
{
    /** GET /price-list — today's computed price for every active catalog item. */
    public function priceList(): JsonResponse
    {
        $live = LiveRate::latestFor('IN');
        $rates = ['gold' => (float) ($live->gold ?? 0), 'silver' => (float) ($live->silver ?? 0)];

        $rows = CatalogProduct::where('is_active', true)
            ->where('is_custom_order', false)   // system items carrying customized-order pieces are not for sale
            ->whereIn('material', ['gold', 'silver'])
            ->orderBy('material')
            ->orderBy('default_weight')
            ->get()
            ->map(function (CatalogProduct $p) use ($rates) {
                $rate = $rates[$p->material] ?? 0.0;
                $metal = $rate * (float) $p->default_weight;
                $charges = (float) $p->making_charge_pct + (float) $p->wastage_charge_pct;
                $price = $metal * (1 + $charges / 100) + (float) $p->hallmark_charge;
                $price *= 1 + ((float) $p->gst_pct) / 100;

                return [
                    'name' => Translatable::pick($p->name),
                    'material' => $p->material,
                    'weight' => (float) $p->default_weight,
                    'price' => round($price, 2),
                ];
            })
            ->values();

        return response()->json([
            'date' => now()->toDateString(),
            'rates' => $rates,
            'data' => $rows,
        ]);
    }

    /** GET /calculator/plans — active plans for the calculator picker. */
    public function calculatorPlans(): JsonResponse
    {
        return response()->json([
            'data' => Plan::where('is_active', true)->orderBy('code')->get()
                ->map(fn (Plan $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => Translatable::pick($p->name) ?: $p->code,
                    'min_value' => (float) $p->min_value,
                ])->values(),
        ]);
    }

    /**
     * POST /calculator {plan_id, amount} — the legacy Business Calculator core:
     * how a billed amount splits into goods (invoice allocation) vs contract, the
     * approximate 916 gold the goods buy after GST + weight-bracket charges, and
     * the plan's CBC worth.
     */
    public function calculator(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);
        $amount = (float) $data['amount'];

        if ($amount < (float) $plan->min_value) {
            return response()->json([
                'message' => 'Minimum for ' . (Translatable::pick($plan->name) ?: $plan->code)
                    . ' is ₹' . \App\Support\Money::group((float) $plan->min_value) . '.',
            ], 422);
        }

        $goldRate = (float) (LiveRate::latestFor('IN')->gold ?? 0);
        if ($goldRate <= 0) {
            return response()->json(['message' => 'Live gold rate unavailable — try again shortly.'], 422);
        }

        $allocPct = (float) $plan->allocation_bv;
        $invoiceAlloc = round($amount * $allocPct / 100, 2);
        $contractAlloc = round($amount - $invoiceAlloc, 2);

        // Strip 3% GST, then the weight-bracket making+wastage, to reach real metal.
        $net = $invoiceAlloc * 100 / 103;
        $approxGrams = $net / $goldRate;
        $bracket = ChargeBracket::where('material', 'gold')
            ->where('wt_from', '<=', $approxGrams)
            ->where('wt_to', '>=', $approxGrams)
            ->first();
        $chargePct = $bracket ? (float) $bracket->making_pct + (float) $bracket->wastage_pct : 0.0;
        $metalValue = $net * 100 / (100 + $chargePct);
        $grams = $metalValue / $goldRate;

        $cbcTotal = round($amount * ((float) $plan->cbc_value) / 100, 2);

        return response()->json([
            'plan' => ['code' => $plan->code, 'name' => Translatable::pick($plan->name) ?: $plan->code],
            'gold_rate' => $goldRate,
            'amount' => $amount,
            'gold_grams' => round($grams, 2),
            'invoice_allocation' => $invoiceAlloc,
            'contract_allocation' => $contractAlloc,
            'charges_pct' => $chargePct,
            'cbc_total' => $cbcTotal,
            // Item 2a (board 2026-08-12): the same benefit lines the CONTRACT
            // prints, computed for this amount — plan-code semantics mirror
            // ContractContentBuilder so the calculator and the PDF always agree.
            'benefits' => $this->benefitRows($plan, $amount),
        ]);
    }

    /** Contract-style benefit rows [{label, value}] for a plan + amount. */
    protected function benefitRows(Plan $plan, float $amount): array
    {
        $code = (int) preg_replace('/\D/', '', (string) ($plan->code ?? ''));
        $inr = fn (float $n) => \App\Support\Money::inr($n);

        // RD savings plans — monthly amount × validity + bonus months.
        if (($plan->type ?? null) === 'rd' || in_array($code, [200, 208, 209], true)) {
            $months = (int) ($plan->validity_months ?: 11);
            $bonus = $code === 200 ? 3 : ($code === 208 ? 2 : 1);

            return [
                ['label' => "Savings {$inr($amount)} × {$months} months", 'value' => $inr($amount * $months)],
                ['label' => "{$bonus} month(s) Bonus", 'value' => $inr($amount * $bonus)],
                ['label' => 'Maturity worth', 'value' => $inr($amount * ($months + $bonus))],
            ];
        }

        // Dealership plans — the contract's amount split.
        $rows = match (true) {
            $code === 201 => [
                ['label' => 'SPOT 916 HUID Gold Coins (or) Bar : 50%', 'value' => $inr($amount / 2)],
                ['label' => '24 months Contract : 50%', 'value' => $inr($amount / 2)],
            ],
            $code === 205 => [
                ['label' => 'SPOT 916 HUID Gold Coins (or) Bar : 70%', 'value' => $inr($amount * 0.70)],
                ['label' => '24 months Contract : 30%', 'value' => $inr($amount * 0.30)],
            ],
            $code === 202 => [
                ['label' => '12 months Contract : 100%', 'value' => $inr($amount)],
            ],
            in_array($code, [203, 204, 207, 214], true) => [
                ['label' => 'spot Gold 916 HUID Coins (or) Gold Bar (80%)', 'value' => $inr($amount * 0.80)],
                ['label' => 'Interior (10%)', 'value' => $inr($amount * 0.10)],
                ['label' => 'Refundable Deposit (10%)', 'value' => $inr($amount * 0.10)],
            ],
            default => [
                ['label' => 'Contract amount', 'value' => $inr($amount)],
                ['label' => 'Validity', 'value' => (int) ($plan->validity_months ?: 12) . ' months'],
            ],
        };

        // Monthly cashback line where the plan pays CBC.
        if ((float) $plan->cbc_value > 0 && (int) $plan->cbc_count > 0) {
            $monthlyCbc = round($amount * (float) $plan->cbc_value / 100 / max(1, (int) $plan->cbc_count), 2);
            $rows[] = [
                'label' => "Promotional Incentive (CBC) {$inr($monthlyCbc)} × {$plan->cbc_count} months",
                'value' => $inr($monthlyCbc * (int) $plan->cbc_count),
            ];
        }

        return $rows;
    }

    /**
     * GET /posts?type=news|blog — Events & News for the app Info screen (item 20).
     * Public; images come out on the REQUEST host so LAN devices can load them.
     */
    public function posts(Request $request): JsonResponse
    {
        $type = $request->query('type', 'news');
        abort_unless(in_array($type, ['news', 'blog'], true), 422, 'type must be news or blog');

        return response()->json([
            'data' => \App\Models\Post::query()
                ->published()
                ->type($type)
                ->orderByDesc('published_at')
                ->limit(20)
                ->get()
                ->map(fn (\App\Models\Post $p) => [
                    'id' => $p->id,
                    'type' => $p->type,
                    'title' => Translatable::pick($p->title),
                    'excerpt' => Translatable::pick($p->excerpt),
                    'body' => Translatable::pick($p->body),
                    'image' => \App\Http\Resources\ProductResource::imageUrl($p->image_path),
                    'author' => $p->author_name,
                    'published_at' => optional($p->published_at)->toIso8601String(),
                ])->values(),
        ]);
    }

    /** GET /stores — active branches for the Store Locator. */
    public function stores(): JsonResponse
    {
        return response()->json([
            'data' => Branch::where('is_active', true)->orderBy('name')->get()
                ->map(fn (Branch $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'incharge' => $b->incharge,
                    'phone' => $b->phone,
                    'address' => trim(implode(', ', array_filter([$b->address, $b->city, $b->pincode]))),
                    'latitude' => $b->latitude !== null ? (float) $b->latitude : null,
                    'longitude' => $b->longitude !== null ? (float) $b->longitude : null,
                ])->values(),
        ]);
    }
}
