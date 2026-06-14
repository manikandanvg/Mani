<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// LiveRate resolved in same namespace (App\Models)

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'weight' => 'decimal:4',
        'stone_weight' => 'decimal:4',
        'base_price' => 'decimal:2',
        'hallmark_charge' => 'decimal:2',
        'stock_qty' => 'decimal:4',
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function category() { return $this->belongsTo(Category::class); }
    public function subcategory() { return $this->belongsTo(Category::class, 'subcategory_id'); }
    public function images() { return $this->hasMany(ProductImage::class); }

    /** Rate-based pieces (gold/silver/diamond) are priced live from weight × metal rate. */
    public function isRateBased(): bool
    {
        return in_array($this->material, ['gold', 'silver', 'diamond'], true) && (float) $this->weight > 0;
    }

    /**
     * Transparent price breakup in BASE currency. For rate-based pieces:
     *   metal value = weight × live rate
     *   + making  (making_charge_pct % of metal value)
     *   + wastage (wastage_charge_pct % of metal value)
     *   + hallmark (hallmark_charge % of metal value)   = ex-GST subtotal (cart unit price)
     *   + GST (% of subtotal)                            = total
     * Each charge is itemised on its own line. Accessories/fixed-price items fall back
     * to base_price as the subtotal.
     *
     * @return array{rate_based:bool,has_rate:bool,weight:float,purity:?string,rate:float,
     *   metal_value:float,making_pct:float,making:float,wastage_pct:float,wastage:float,
     *   hallmark_pct:float,hallmark:float,charges:float,subtotal:float,gst_pct:float,
     *   gst:float,total:float}
     */
    public function priceBreakup(string $country = 'IN'): array
    {
        $gstPct = (float) ($this->gst_pct ?? 0);
        $rateBased = $this->isRateBased();
        $rate = 0.0;
        $metalValue = $making = $wastage = $hallmark = 0.0;

        // making / wastage / hallmark are each a percentage of the metal value.
        $makingPct = (float) ($this->making_charge_pct ?? 0);
        $wastagePct = (float) ($this->wastage_charge_pct ?? 0);
        $hallmarkPct = (float) ($this->hallmark_charge ?? 0);

        if ($rateBased) {
            $live = LiveRate::latestFor($country);
            $rate = $live ? (float) ($live->{$this->material} ?? 0) : 0.0;
        }

        if ($rateBased && $rate > 0) {
            $weight = (float) $this->weight;
            $metalValue = round($weight * $rate, 2);
            $making = round($metalValue * $makingPct / 100, 2);
            $wastage = round($metalValue * $wastagePct / 100, 2);
            $hallmark = round($metalValue * $hallmarkPct / 100, 2);
            $subtotal = round($metalValue + $making + $wastage + $hallmark, 2);
        } else {
            $subtotal = (float) ($this->base_price ?? 0);
        }

        $gst = round($subtotal * $gstPct / 100, 2);

        return [
            'rate_based' => $rateBased,
            'has_rate' => $rateBased && $rate > 0,
            'weight' => (float) $this->weight,
            'purity' => $this->purity,
            'rate' => $rate,
            'metal_value' => $metalValue,
            'making_pct' => $makingPct,
            'making' => $making,
            'wastage_pct' => $wastagePct,
            'wastage' => $wastage,
            'hallmark_pct' => $hallmarkPct,
            'hallmark' => $hallmark,
            'charges' => round($making + $wastage + $hallmark, 2),
            'subtotal' => round($subtotal, 2),   // ex-GST = cart unit price
            'gst_pct' => $gstPct,
            'gst' => $gst,
            'total' => round($subtotal + $gst, 2),
        ];
    }

    /** Final displayed price (incl. GST) in base currency, for cards/listings. */
    public function displayTotal(string $country = 'IN'): float
    {
        return $this->priceBreakup($country)['total'];
    }
}
