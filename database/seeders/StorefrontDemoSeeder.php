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
            ['code' => 'EA001', 'name' => 'Silver Pooja Plate', 'cat' => $acc, 'material' => 'utility', 'weight' => 120.0, 'purity' => '925', 'price' => 9800, 'feat' => true],
            ['code' => 'EA002', 'name' => 'Gold-plated Spoon Set', 'cat' => $acc, 'material' => 'utility', 'weight' => 80.0, 'purity' => '—', 'price' => 5400, 'feat' => false],
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
            ['slug' => 'about', 'group' => 'page', 'title' => 'About Us', 'body' => self::aboutBody()],
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
    /**
     * Public "About Us" copy (board 2026-10-24: the page needs real content). Also used by
     * the roll-out migration to fill an existing page that still carries the demo one-liner.
     */
    public static function aboutBody(): string
    {
        return <<<'HTML'
<p><strong>LORD JEWELLER</strong> is the jewellery house of <strong>Global Gold Pvt Ltd</strong>, headquartered at Sivakasi, Tamil Nadu. We make and sell <em>Blessed Gold</em> â HUID-hallmarked, BIS-certified 22K gold and 925 silver â crafted by heritage goldsmiths and priced to the minute on the live metal rate, so every customer pays exactly what the day's market says, nothing hidden.</p>
<h2>Made with heritage</h2>
<p>Our chains, bangles, rings, coins and bridal sets are made in the traditional South Indian way and finished to modern standards. Every gold piece carries its HUID hallmark; every silver piece is purity-stamped. Alongside jewellery we craft <strong>utility pieces</strong> for the home and for gifting â gold spoons, silver tumblers, pooja articles and more.</p>
<h2>Gold for every family</h2>
<p>Gold should be reachable, not only on a wedding day. Our savings and purchase schemes let a family build gold a little at a time: a monthly recurring plan that matures into a gold QR, outright gold and silver purchase plans, and a digital gold purse that can be bought in rupees and redeemed as metal at any branch. Each scheme is written down as a contract, settled on its due date, and tracked in the Lord app.</p>
<h2>A network you can walk into</h2>
<p>Lord Jeweller serves customers through its own <strong>Lord's Branches</strong> and an authorised network of <strong>dealers</strong> across Tamil Nadu and beyond, each running the same live pricing, the same hallmarked stock and the same contract terms as the head office. Find the branch or dealer nearest you on our <a href="/stores">Branches &amp; Dealers</a> page.</p>
<h2>Our promise</h2>
<ul>
<li>HUID hallmarked 22K gold and certified 925 silver, always.</li>
<li>Live, transparent pricing â the rate is shown before you buy.</li>
<li>Every scheme backed by a written contract and a settlement date.</li>
<li>Lifetime exchange on hallmarked gold at every branch and dealer.</li>
</ul>
<p>Head Office: 62K Gandhi Road, Jeahova Nishi Complex, First Floor, Sivakasi 626123, Tamil Nadu, India.</p>
HTML;
    }
}
