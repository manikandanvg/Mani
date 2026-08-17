<?php

namespace App\Http\Resources;

use App\Support\Translatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A storefront product for the mobile shop. Price is computed server-side
 * (Product::priceBreakup — live metal rate + charges + GST) so the client never
 * does money math. The `name`/`description` localise to the request `?lang=`.
 *
 * Images + category are included when eager-loaded (detail view); the list view
 * sends just the primary image and the price.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lang = $request->query('lang');
        $price = $this->priceBreakup();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => Translatable::pick($this->name, $lang) ?: $this->code,
            'material' => $this->material,
            'weight' => (float) $this->weight,
            'purity' => $this->purity,
            'is_featured' => (bool) $this->is_featured,
            // null = stock not tracked (made to order at the live rate) → available;
            // only an explicit admin-set 0 reads as out of stock.
            'in_stock' => $this->stock_qty === null || (float) $this->stock_qty > 0,
            'image' => static::imageUrl($this->images->first()?->path ?? null),
            'price' => [
                'currency' => 'INR',
                'rate_based' => $price['rate_based'],
                'has_rate' => $price['has_rate'],
                'subtotal' => $price['subtotal'],
                'gst_pct' => $price['gst_pct'],
                'gst' => $price['gst'],
                'total' => $price['total'],
            ],

            // Detail-only fields (present when the relations/breakup are requested).
            'description' => $this->when(
                $request->routeIs('*product*') || $this->relationLoaded('category'),
                fn () => Translatable::pick($this->description, $lang),
            ),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => Translatable::pick($this->category->name, $lang),
            ] : null),
            'images' => $this->whenLoaded(
                'images',
                fn () => $this->images->sortBy('sort')->map(fn ($i) => static::imageUrl($i->path))->filter()->values(),
            ),
            'price_breakup' => $this->when(
                $this->relationLoaded('images'),
                fn () => $price,
            ),
        ];
    }

    /**
     * Build a public URL for a stored image path (pass through absolute URLs).
     *
     * Derived from the REQUEST host, not APP_URL — the phone calls the API via
     * the LAN IP (or the live domain) and must get image links on that same host.
     * Storage::url()/asset() would bake in APP_URL (a dev-only hostname the
     * device cannot resolve) — the exact bug that once silenced the L-BOX audio.
     * Legacy `images/...` paths live under public/ directly; everything else is
     * on the public disk behind the /storage symlink.
     */
    public static function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return Str::startsWith($path, 'images/') ? url($path) : url('storage/' . $path);
    }
}
