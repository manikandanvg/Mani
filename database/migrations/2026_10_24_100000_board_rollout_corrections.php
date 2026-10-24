<?php

use App\Models\CmsPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board resolution 2026-10-24 (final pre-roll-out corrections):
 *
 *  - branches.kind — every dealership is EITHER an independent "dealer" OR one of
 *    "Lord's Branches" (the dealer becomes the branch in-charge; by TBP stage they move
 *    onto the payroll as an employee). Some dealers keep dealer status, many convert.
 *  - plans.available_to — a scheme may be billable by both kinds, by Lord's Branches
 *    only (e.g. RD / G12 contracts) or by dealers only. Edited on the Scheme form and
 *    enforced at billing.
 *  - products.material gains "utility" (gold spoons, silver tumblers …) — the public
 *    "Accessories" category is renamed "Utility".
 *  - Head office is Sivakasi (not the seeded Coimbatore placeholder).
 *  - The public About page gets real content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('kind', 12)->default('dealer')->after('level')
                ->comment("dealer = independent dealership · branch = Lord's own branch (dealer is the in-charge)");
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->string('available_to', 12)->default('all')->after('is_active')
                ->comment("all · branch (Lord's Branches only) · dealer (dealers only)");
        });

        // Head office is a Lord's Branch by definition.
        DB::table('branches')->where('level', 'hq')->orWhere('id', DB::table('branches')->min('id'))->update(['kind' => 'branch']);

        // products.material: add "utility" and migrate the old "accessory" value.
        // ->change() rebuilds the CHECK constraint on SQLite (tests) and MODIFYs the ENUM on MySQL.
        Schema::table('products', fn (Blueprint $t) => $t->enum('material', ['gold', 'silver', 'accessory', 'utility', 'diamond', 'other'])->default('gold')->change());
        Schema::table('catalog_products', fn (Blueprint $t) => $t->enum('material', ['gold', 'silver', 'vessel', 'utility', 'cash'])->default('gold')->change());
        Schema::table('categories', fn (Blueprint $t) => $t->enum('material', ['gold', 'silver', 'vessel', 'accessory', 'utility', 'diamond', 'other', 'cash'])->default('gold')->change());
        // digi_orders.source: "OPENING" = the legacy roll-out balance line (one per member).
        Schema::table('digi_orders', fn (Blueprint $t) => $t->enum('source', ['IC', 'SP', 'LEVEL', 'BM', 'OPENING'])->default('IC')->change());
        DB::table('products')->where('material', 'accessory')->update(['material' => 'utility']);
        DB::table('categories')->where('material', 'accessory')->update(['material' => 'utility']);
        DB::table('categories')->where('slug', 'ecom-accessories')
            ->update(['name' => json_encode(['en' => 'Utility'])]);

        // Head office → Sivakasi (only the seeded placeholder is touched).
        DB::table('branches')->where('city', 'SIVAKASI')->where('gst_no', '33AREPB3962B3ZD')->update([
            'name' => 'LORD JEWELLER',
            'address' => '62K Gandhi Road, Jeahova Nishi Complex, First Floor',
            'city' => 'Sivakasi',
            'pincode' => '626123',
            'phone' => '+91 95142 77844',
            'gst_no' => '33AREPB3962B3ZD',
            'latitude' => 9.4533,
            'longitude' => 77.7978,
        ]);

        // About page content (board: "put some content") — an EXISTING page that still carries
        // the demo one-liner gets the real copy; a fresh database gets the page from RolloutSeeder.
        if (Schema::hasTable('cms_pages') && ($page = CmsPage::where('slug', 'about')->first())) {
            $current = (string) (is_array($page->body) ? ($page->body['en'] ?? '') : $page->body);
            if (mb_strlen(strip_tags($current)) < 220) {   // never overwrite real copy
                $page->body = array_merge(is_array($page->body) ? $page->body : [], ['en' => \Database\Seeders\StorefrontDemoSeeder::aboutBody()]);
                $page->save();
            }
        }
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('available_to'));
        DB::table('products')->where('material', 'utility')->update(['material' => 'accessory']);
        DB::table('categories')->where('material', 'utility')->update(['material' => 'accessory']);
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE products MODIFY material ENUM('gold','silver','accessory','diamond','other') NOT NULL DEFAULT 'gold'");
        }
    }
};
