<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;

/**
 * Assigns the locally-stored storefront imagery (public/images/**) to the
 * e-commerce categories, products and blog/news posts. Idempotent — safe to
 * re-run; it only fills the reference columns / product_images rows.
 */
class StorefrontImageSeeder extends Seeder
{
    public function run(): void
    {
        // Root e-commerce categories, matched by their English name.
        $categoryImages = [
            'Gold' => 'images/categories/gold.jpg',
            'Silver' => 'images/categories/silver.jpg',
            'Accessories' => 'images/categories/accessories.jpg',
        ];
        foreach (Category::where('domain', 'ecommerce')->whereNull('parent_id')->get() as $cat) {
            $name = is_array($cat->name) ? ($cat->name['en'] ?? '') : (string) $cat->name;
            if (isset($categoryImages[$name])) {
                $cat->update(['image_path' => $categoryImages[$name]]);
            }
        }

        // Products, matched by code.
        $productImages = [
            'EG001' => 'images/products/eg001-gold-chain.jpg',
            'EG002' => 'images/products/eg002-gold-bangle.jpg',
            'EG003' => 'images/products/eg003-jhumka-earrings.jpg',
            'EG004' => 'images/products/eg004-bridal-necklace.jpg',
            'ES001' => 'images/products/es001-silver-anklet.jpg',
            'ES002' => 'images/products/es002-silver-ring.jpg',
            'ES003' => 'images/products/es003-silver-chain.jpg',
            'EA001' => 'images/products/ea001-pooja-gift.jpg',
            'EA002' => 'images/products/ea002-gold-accessory.jpg',
        ];
        foreach ($productImages as $code => $path) {
            $product = Product::withTrashed()->where('code', $code)->first();
            if ($product) {
                ProductImage::updateOrCreate(
                    ['product_id' => $product->id, 'sort' => 0],
                    ['path' => $path],
                );
            }
        }

        // Blog / news posts, matched by slug.
        $postImages = [
            'caring-for-gold' => 'images/posts/caring-for-gold.jpg',
            'choosing-bridal-set' => 'images/posts/choosing-bridal-set.jpg',
            'new-coimbatore-store' => 'images/posts/new-coimbatore-store.jpg',
            'gold-savings-launch' => 'images/posts/gold-savings-launch.jpg',
        ];
        foreach ($postImages as $slug => $path) {
            Post::where('slug', $slug)->update(['image_path' => $path]);
        }
    }
}
