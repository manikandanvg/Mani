<?php

namespace App\Support;

use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Plan;
use App\Models\Setting;
use App\Services\Charges\ChargeResolver;

/**
 * Pricing rules for the distributor "Customize Order Form" (board 2026-08-26, corrected
 * the same day):
 *
 *   Base           = (live rate + marginal cost per gram) × quantity (grams)
 *   Charges        = making % + wastage % from the CHARGE BRACKET slab the weight falls
 *                    in (Master → Charge Brackets) — applied automatically once grams
 *                    are typed; there is no hand-typed cost.
 *   GST            = on (base + charges)
 *   Net total      = base + charges + GST
 *
 * The margin and GST live in the settings table (group `customize_order`) and are
 * edited on Commission Setup; defaults apply until HQ saves them. A customer's RD
 * 100 mg coins applied to an order are valued at the plain live metal rate
 * (grams × ₹/g, no charges) — they are metal handed back, not a resale.
 */
class CustomizeOrderPricing
{
    public const GROUP = 'customize_order';

    public const MATERIALS = ['gold' => 'Gold', 'silver' => 'Silver'];

    public const DEFAULT_GST_PCT = 3.0;

    /** Per-gram marginal cost HQ adds on top of the live rate, by metal. */
    public static function marginPerGram(string $material): float
    {
        return (float) (static::setting($material . '_margin_per_g') ?? 0);
    }

    public static function gstPct(): float
    {
        $v = static::setting('gst_pct');

        return $v === null ? self::DEFAULT_GST_PCT : (float) $v;
    }

    /** Live ₹/g for a metal (IN region). */
    public static function liveRate(string $material, ?LiveRate $rate = null): float
    {
        $rate ??= LiveRate::latestFor('IN') ?? LiveRate::query()->latest('id')->first();

        return $material === 'silver' ? (float) ($rate->silver ?? 0) : (float) ($rate->gold ?? 0);
    }

    /**
     * Price one customised line.
     *
     * @return array{material:string,rate:float,margin_per_g:float,unit_price:float,making_pct:float,wastage_pct:float,bracket_id:?int,base:float,charges:float,gst_pct:float,net:float,gst:float,line_total:float}
     */
    public static function priceLine(string $material, float $grams, ?LiveRate $rate = null): array
    {
        $material = array_key_exists($material, self::MATERIALS) ? $material : 'gold';
        $grams = max(0.0, $grams);
        $live = static::liveRate($material, $rate);
        $margin = static::marginPerGram($material);
        $unit = round($live + $margin, 2);
        $base = round($unit * $grams, 2);

        $slab = $grams > 0 ? app(ChargeResolver::class)->forWeight($material, $grams) : ['making_pct' => 0.0, 'wastage_pct' => 0.0, 'bracket_id' => null];
        $charges = round($base * ($slab['making_pct'] + $slab['wastage_pct']) / 100, 2);
        $net = round($base + $charges, 2);
        $gstPct = static::gstPct();
        $gst = round($net * $gstPct / 100, 2);

        return [
            'material' => $material,
            'rate' => round($live, 2),
            'margin_per_g' => round($margin, 2),
            'unit_price' => $unit,
            'making_pct' => (float) $slab['making_pct'],
            'wastage_pct' => (float) $slab['wastage_pct'],
            'bracket_id' => $slab['bracket_id'],
            'base' => $base,
            'charges' => $charges,
            'gst_pct' => $gstPct,
            'net' => $net,
            'gst' => $gst,
            'line_total' => round($net + $gst, 2),
        ];
    }

    /** Value of returned coins/metal: grams × live ₹/g (no making, no GST). */
    public static function metalValue(string $material, float $grams, ?LiveRate $rate = null): float
    {
        return round(max(0.0, $grams) * static::liveRate($material, $rate), 2);
    }

    /**
     * The catalog product that represents the RD 100 mg gold coin — so collected coins
     * can be counted into branch stock. Explicit setting wins; else the product an RD
     * gold-QR plan mints against; else a 0.1 g gold catalog item.
     */
    public static function coinProduct(): ?CatalogProduct
    {
        $id = static::setting('coin_product_id');
        if ($id && ($cp = CatalogProduct::find((int) $id))) {
            return $cp;
        }
        $planProduct = Plan::whereNotNull('rd_qr_product_id')->where('rd_qr_grams', '>', 0)->value('rd_qr_product_id');
        if ($planProduct && ($cp = CatalogProduct::find($planProduct))) {
            return $cp;
        }

        return CatalogProduct::where('material', 'gold')->where('is_active', true)
            ->whereBetween('default_weight', [0.0999, 0.1001])->orderBy('id')->first();
    }

    /** Coin-like catalog items (per-piece metal products) a customer may hand back. */
    public static function coinProductOptions(): array
    {
        $locale = Translatable::defaultLocale();

        return CatalogProduct::whereIn('material', ['gold', 'silver'])->where('is_active', true)
            ->where('default_weight', '>', 0)->orderBy('material')->orderBy('default_weight')->get()
            ->mapWithKeys(fn (CatalogProduct $p) => [
                $p->id => $p->code . ' — ' . Translatable::pick($p->name, $locale)
                    . ' (' . strtoupper($p->material) . ' · '
                    . rtrim(rtrim(number_format((float) $p->default_weight, 4), '0'), '.') . ' g/pc)',
            ])->all();
    }

    /** Persist the HQ-editable rules (Commission Setup → Customize Order pricing). */
    public static function save(array $values): void
    {
        foreach (['gold_margin_per_g', 'silver_margin_per_g', 'gst_pct', 'coin_product_id'] as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $v = $values[$key];
            Setting::updateOrCreate(
                ['group' => self::GROUP, 'key' => $key],
                ['value' => $v === null || $v === '' ? null : (string) $v, 'type' => $key === 'coin_product_id' ? 'int' : 'float'],
            );
        }
    }

    protected static function setting(string $key): ?string
    {
        $v = Setting::where('group', self::GROUP)->where('key', $key)->value('value');

        return $v === null || $v === '' ? null : (string) $v;
    }
}
