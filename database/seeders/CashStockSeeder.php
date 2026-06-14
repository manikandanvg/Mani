<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Category;
use App\Models\Stock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Imports the legacy CASH stock — every branch holds a cash balance (legacy tbl_stock
 * catename='CASH', prid=3, purprice=1, weight = rupee value). Creates the CASH category
 * + catalog product, then sets each branch's cash quantity to the legacy sum.
 *
 * Idempotent: re-running SETS the balance (does not accumulate), and maps legacy branch
 * ids to new branch ids by name. Safe to run any number of times.
 *
 *   php artisan db:seed --class=Database\\Seeders\\CashStockSeeder
 */
class CashStockSeeder extends Seeder
{
    public function run(): void
    {
        $legacy = DB::connection('legacy');

        // --- CASH category + subcategory (trade domain) ---
        $cat = Category::firstOrCreate(
            ['slug' => 'trade-cash'],
            ['name' => ['en' => 'Cash'], 'domain' => 'trade', 'material' => 'cash'],
        );
        $sub = Category::firstOrCreate(
            ['slug' => 'trade-sub-cash-product'],
            ['name' => ['en' => 'Cash Product'], 'domain' => 'trade', 'material' => 'cash', 'parent_id' => $cat->id],
        );

        // --- the single CASH catalog product (legacy prid=3, rate ₹1/unit, no charges/GST) ---
        $cash = CatalogProduct::updateOrCreate(
            ['code' => '3'],
            [
                'name' => ['en' => 'CASH'],
                'category_id' => $cat->id,
                'subcategory_id' => $sub->id,
                'material' => 'cash',
                'default_purity' => null,
                'making_charge_pct' => 0,
                'wastage_charge_pct' => 0,
                'hallmark_charge' => 0,
                'gst_pct' => 0,
                'is_active' => true,
            ],
        );

        // --- legacy branch id => new branch id, matched by name (brid 1 = HQ) ---
        $hqId = Branch::min('id');
        $byName = Branch::pluck('id', 'name');
        $branchMap = [1 => $hqId];
        foreach ($legacy->table('tbl_branches')->get() as $b) {
            if ((int) $b->brid === 1) {
                continue;
            }
            $name = mb_substr($b->storename ?: ('Branch ' . $b->brid), 0, 200);
            if (isset($byName[$name])) {
                $branchMap[(int) $b->brid] = $byName[$name];
            }
        }

        // --- sum cash weight (= rupee balance) per legacy branch ---
        $rows = $legacy->table('tbl_stock')
            ->select('branchid', DB::raw('SUM(weight) as qty'), DB::raw('MAX(purprice) as rate'))
            ->whereRaw('UPPER(catename) = ?', ['CASH'])
            ->groupBy('branchid')
            ->get();

        $count = 0;
        foreach ($rows as $r) {
            $branchId = $branchMap[(int) $r->branchid] ?? null;
            if (! $branchId) {
                continue;
            }
            Stock::updateOrCreate(
                ['branch_id' => $branchId, 'catalog_product_id' => $cash->id],
                ['quantity' => round((float) $r->qty, 4), 'purity' => null, 'last_rate' => (float) ($r->rate ?: 1)],
            );
            $count++;
        }

        // every branch should have a cash row, even if legacy had none (start at 0)
        $seeded = 0;
        foreach (Branch::pluck('id') as $bid) {
            $stock = Stock::firstOrCreate(
                ['branch_id' => $bid, 'catalog_product_id' => $cash->id],
                ['quantity' => 0, 'purity' => null, 'last_rate' => 1],
            );
            if ($stock->wasRecentlyCreated) {
                $seeded++;
            }
        }

        $this->command?->info("Cash stock imported: {$count} branches from legacy, {$seeded} more initialised to 0.");
    }
}
