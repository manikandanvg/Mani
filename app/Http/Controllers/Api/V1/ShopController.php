<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public storefront catalog for the mobile shop (Phase 1). Mirrors the website's
 * StorefrontController filtering, but returns JSON resources with server-computed
 * prices. No auth — browsing is open to the public.
 */
class ShopController extends Controller
{
    /** GET /products — filterable, paginated list. */
    public function products(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'category' => ['nullable', 'integer'],
            'material' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:100'],
            'featured' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()->where('is_active', true)->with('images');

        if ($request->filled('category')) {
            $cat = (int) $request->query('category');
            $query->where(fn ($q) => $q->where('category_id', $cat)->orWhere('subcategory_id', $cat));
        }
        if ($request->filled('material')) {
            $query->where('material', $request->query('material'));
        }
        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }
        if ($request->filled('q')) {
            $term = $request->query('q');
            $query->where(fn ($q) => $q->where('code', 'like', "%{$term}%")
                ->orWhere('name->en', 'like', "%{$term}%"));
        }

        $products = $query->latest()->paginate((int) $request->query('per_page', 12))->withQueryString();

        return ProductResource::collection($products);
    }

    /** GET /products/{product} — full detail with images, category, and price breakup. */
    public function product(Product $product): JsonResource
    {
        abort_unless($product->is_active, 404);
        $product->load(['category', 'images']);

        return new ProductResource($product);
    }

    /** GET /categories — active ecommerce root categories with their children. */
    public function categories(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('domain', 'ecommerce')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort')])
            ->orderBy('sort')
            ->get();

        return CategoryResource::collection($categories);
    }
}
