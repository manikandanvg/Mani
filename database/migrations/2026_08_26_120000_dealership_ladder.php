<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dealership ladder (board 2026-08-26): branch levels follow the Plans `hid` order —
 *   0 hq · 1 regional · 2 zonal · 3 district · 4 taluk (showroom / L-BOX, last node of the
 *   distributor chain) · 5 reseller (G5 Retailer) · 6 sub_dealer · 7 wholesaler (G24) ·
 *   8 area_dealer.
 * Two new levels (regional, sub_dealer); Regional now sells on, so the transfer-margin
 * rate card gains a column for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->enum('level', [
                'hq', 'regional', 'zonal', 'district', 'taluk', 'reseller', 'sub_dealer', 'wholesaler', 'area_dealer',
            ])->nullable()->change();
        });
        Schema::table('stock_transfer_margins', function (Blueprint $table) {
            $table->decimal('regional_pct', 8, 3)->default(0)->after('catalog_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_margins', fn (Blueprint $table) => $table->dropColumn('regional_pct'));
        Schema::table('branches', function (Blueprint $table) {
            $table->enum('level', ['hq', 'zonal', 'district', 'taluk', 'wholesaler', 'reseller', 'area_dealer'])->nullable()->change();
        });
    }
};
