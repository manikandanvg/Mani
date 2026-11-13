<?php

use App\Models\CatalogProduct;
use App\Services\Redeem\RedemptionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-13: redemption invoices printed "1 MG GOLD" for the 100 mg piece (and "5 MG GOLD"
 * for 500 mg, "3 MG GOLD" for 300 mg …) — the old label trimmed the zeros off the milligrams.
 * "Description of Goods" is now the Catalog Product name in capitals ("100 MG GOLD"); this renames the lines already saved
 * by the new system. Lines imported from the old system already carry the product name and are
 * left alone. Data only, safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $products = CatalogProduct::all()->keyBy('id');

        DB::table('redemption_lines as l')
            ->join('redemption_invoices as i', 'i.id', '=', 'l.redemption_invoice_id')
            ->whereNull('i.legacy_restock')
            ->whereNotNull('l.catalog_product_id')
            ->orderBy('l.id')
            ->select('l.id', 'l.catalog_product_id', 'l.unit_weight', 'l.description')
            ->chunkById(500, function ($rows) use ($products) {
                foreach ($rows as $r) {
                    $cp = $products->get($r->catalog_product_id);
                    // only the labels the old code generated itself ("1 MG GOLD", "8 G GOLD", bare "GOLD")
                    if (! $cp || ! preg_match('/^(?:[\d.,]+\s+M?G\s+)?[A-Z]+$/', trim((string) $r->description))) {
                        continue;
                    }
                    $name = RedemptionService::lineLabel($cp, (float) $r->unit_weight);
                    if ($name !== $r->description) {
                        DB::table('redemption_lines')->where('id', $r->id)->update(['description' => mb_substr($name, 0, 200)]);
                    }
                }
            }, 'l.id', 'id');
    }

    public function down(): void
    {
        // the old labels were wrong; nothing to restore
    }
};
