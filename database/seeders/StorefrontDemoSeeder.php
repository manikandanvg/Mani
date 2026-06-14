<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CmsPage;
use App\Models\Faq;
use App\Models\Post;
use App\Models\Product;
use App\Models\Testimonial;
use Illuminate\Database\Seeder;

/** Demo content for the public storefront: e-commerce products + CMS. */
class StorefrontDemoSeeder extends Seeder
{
    public function run(): void
    {
        $gold = Category::where('slug', 'ecom-gold')->first();
        $silver = Category::where('slug', 'ecom-silver')->first();
        $acc = Category::where('slug', 'ecom-accessories')->first();

        $products = [
            ['code' => 'EG001', 'name' => 'Classic Gold Chain', 'cat' => $gold, 'material' => 'gold', 'weight' => 12.5, 'purity' => '22K', 'price' => 92500, 'feat' => true],
            ['code' => 'EG002', 'name' => 'Temple Gold Bangle', 'cat' => $gold, 'material' => 'gold', 'weight' => 18.0, 'purity' => '22K', 'price' => 138000, 'feat' => true],
            ['code' => 'EG003', 'name' => 'Gold Jhumka Earrings', 'cat' => $gold, 'material' => 'gold', 'weight' => 8.2, 'purity' => '22K', 'price' => 61500, 'feat' => true],
            ['code' => 'EG004', 'name' => 'Bridal Gold Necklace', 'cat' => $gold, 'material' => 'gold', 'weight' => 42.0, 'purity' => '22K', 'price' => 318000, 'feat' => false],
            ['code' => 'ES001', 'name' => 'Silver Anklet Pair', 'cat' => $silver, 'material' => 'silver', 'weight' => 45.0, 'purity' => '925', 'price' => 4200, 'feat' => true],
            ['code' => 'ES002', 'name' => 'Oxidised Silver Ring', 'cat' => $silver, 'material' => 'silver', 'weight' => 6.0, 'purity' => '925', 'price' => 950, 'feat' => true],
            ['code' => 'ES003', 'name' => 'Silver Chain', 'cat' => $silver, 'material' => 'silver', 'weight' => 22.0, 'purity' => '925', 'price' => 2600, 'feat' => false],
            ['code' => 'EA001', 'name' => 'Silver Pooja Plate', 'cat' => $acc, 'material' => 'accessory', 'weight' => 120.0, 'purity' => '925', 'price' => 9800, 'feat' => true],
            ['code' => 'EA002', 'name' => 'Gold-plated Spoon Set', 'cat' => $acc, 'material' => 'accessory', 'weight' => 80.0, 'purity' => '—', 'price' => 5400, 'feat' => false],
        ];
        foreach ($products as $p) {
            Product::firstOrCreate(['code' => $p['code']], [
                'name' => ['en' => $p['name']],
                'category_id' => optional($p['cat'])->id,
                'material' => $p['material'],
                'weight' => $p['weight'],
                'purity' => $p['purity'],
                'base_price' => $p['price'],
                'making_charge_pct' => 8,
                'gst_pct' => 3,
                'description' => ['en' => 'Handcrafted ' . strtolower($p['name']) . ' from Lord ICL — certified and hallmarked.'],
                'is_featured' => $p['feat'],
                'is_active' => true,
            ]);
        }

        $pages = [
            ['slug' => 'about', 'group' => 'page', 'title' => 'About Us', 'body' => '<p>Lord ICL crafts fine gold and silver jewellery for a global family, blending heritage craftsmanship with modern design.</p>'],
            ['slug' => 'services', 'group' => 'page', 'title' => 'Services', 'body' => '<p>Custom design, gold savings plans, exchange, hallmarking and worldwide delivery.</p>'],
            ['slug' => 'privacy-policy', 'group' => 'policy', 'title' => 'Privacy Policy', 'body' => '<p>We respect your privacy and protect your personal data.</p>'],
            ['slug' => 'terms', 'group' => 'policy', 'title' => 'Terms of Use', 'body' => '<p>By using this site you agree to our terms.</p>'],
            ['slug' => 'shipping-policy', 'group' => 'policy', 'title' => 'Shipping Policy', 'body' => '<p>Insured worldwide shipping on all orders.</p>'],
            ['slug' => 'refund-policy', 'group' => 'policy', 'title' => 'Refund Policy', 'body' => '<p>Refunds processed within 7 business days.</p>'],
            ['slug' => 'return-policy', 'group' => 'policy', 'title' => 'Return / Exchange Policy', 'body' => '<p>15-day return and lifetime exchange on hallmarked gold.</p>'],
        ];
        foreach ($pages as $i => $p) {
            CmsPage::firstOrCreate(['slug' => $p['slug']], [
                'title' => ['en' => $p['title']], 'body' => ['en' => $p['body']],
                'group' => $p['group'], 'sort' => $i, 'is_published' => true,
            ]);
        }

        $posts = [
            ['type' => 'blog', 'slug' => 'caring-for-gold', 'title' => 'How to care for your gold jewellery', 'excerpt' => 'Simple tips to keep your gold radiant for generations.'],
            ['type' => 'blog', 'slug' => 'choosing-bridal-set', 'title' => 'Choosing the perfect bridal set', 'excerpt' => 'A guide to selecting bridal jewellery that lasts.'],
            ['type' => 'news', 'slug' => 'new-coimbatore-store', 'title' => 'New flagship store in Coimbatore', 'excerpt' => 'We opened our largest showroom yet.'],
            ['type' => 'news', 'slug' => 'gold-savings-launch', 'title' => 'Launching global gold savings plans', 'excerpt' => 'Save in gold, in your own currency.'],
        ];
        foreach ($posts as $p) {
            Post::firstOrCreate(['slug' => $p['slug']], [
                'type' => $p['type'],
                'title' => ['en' => $p['title']],
                'excerpt' => ['en' => $p['excerpt']],
                'body' => ['en' => '<p>' . $p['excerpt'] . '</p><p>Read on for the full story from the Lord ICL journal.</p>'],
                'author_name' => 'Lord ICL',
                'published_at' => now()->subDays(rand(1, 30)),
                'is_published' => true,
            ]);
        }

        $testimonials = [
            ['name' => 'Priya R.', 'location' => 'Chennai', 'rating' => 5, 'body' => 'Beautiful craftsmanship and the gold savings plan is brilliant.'],
            ['name' => 'Arjun M.', 'location' => 'Dubai', 'rating' => 5, 'body' => 'Ordered from abroad in AED — smooth and transparent.'],
            ['name' => 'Lakshmi S.', 'location' => 'Coimbatore', 'rating' => 4, 'body' => 'Lovely bridal set, exactly as shown.'],
        ];
        foreach ($testimonials as $i => $t) {
            Testimonial::firstOrCreate(['name' => $t['name']], [
                'location' => $t['location'], 'rating' => $t['rating'],
                'body' => ['en' => $t['body']], 'sort' => $i, 'is_published' => true,
            ]);
        }

        $faqs = [
            ['q' => 'Do you ship internationally?', 'a' => 'Yes — we ship insured worldwide and bill in your local currency.'],
            ['q' => 'Is the gold hallmarked?', 'a' => 'All our gold is BIS hallmarked and certified.'],
            ['q' => 'Can I exchange old gold?', 'a' => 'Yes, visit any branch for exchange against current rates.'],
            ['q' => 'How do gold savings plans work?', 'a' => 'Save monthly and redeem in gold; ask in-store or online.'],
        ];
        foreach ($faqs as $i => $f) {
            Faq::firstOrCreate(['sort' => $i], [
                'question' => ['en' => $f['q']], 'answer' => ['en' => $f['a']],
                'is_published' => true,
            ]);
        }
    }
}
