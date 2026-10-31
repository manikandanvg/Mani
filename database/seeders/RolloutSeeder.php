<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Masters for a FRESH roll-out database (board 2026-10-24) — the DatabaseSeeder without its
 * demo dealer/branch/member. Run on an empty, migrated database, then import the legacy
 * data (which brings the MOUs the v2 scheme sheet points at, so the schemes are seeded by
 * the importer itself):
 *
 *   php artisan migrate --force
 *   php artisan db:seed --class=RolloutSeeder --force
 *   php artisan legacy:rollout --fresh --seed-plans
 *   php artisan db:seed --class=CashStockSeeder --force     # legacy cash stock per branch
 *
 * Seeds: roles, the super admin (admin@lordicl.com / change the password!), currencies,
 * languages, TBP ranks, the Sivakasi head office, e-com + trade categories, live rate,
 * charge brackets.
 */
class RolloutSeeder extends Seeder
{
    public function run(): void
    {
        DatabaseSeeder::$withDemo = false;
        $this->call(DatabaseSeeder::class);
        DatabaseSeeder::$withDemo = true;
        $this->call(StorefrontImageSeeder::class);   // category / product / post imagery under public/images

        // Public pages the storefront links to (About has the board's copy; policies are stubs to edit in Website → Pages).
        foreach ([
            ['slug' => 'about', 'group' => 'page', 'title' => 'About Us', 'body' => StorefrontDemoSeeder::aboutBody()],
            ['slug' => 'services', 'group' => 'page', 'title' => 'Services', 'body' => '<p>Gold savings schemes, gold and silver purchase plans, digital gold, custom design, exchange and HUID hallmarking — at every Lord\'s Branch and dealer.</p>'],
            ['slug' => 'privacy-policy', 'group' => 'policy', 'title' => 'Privacy Policy', 'body' => '<p>We respect your privacy and protect your personal data.</p>'],
            ['slug' => 'terms', 'group' => 'policy', 'title' => 'Terms of Use', 'body' => '<p>By using this site you agree to our terms.</p>'],
        ] as $p) {
            \App\Models\CmsPage::firstOrCreate(['slug' => $p['slug']], [
                'group' => $p['group'], 'title' => ['en' => $p['title']], 'body' => ['en' => $p['body']], 'is_published' => true, 'sort' => 1,
            ]);
        }

        // Board 2026-10-31: premium copy for every page except About Us + the portal FAQ.
        $this->call(CmsContentSeeder::class);
    }
}
