<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock-transfer margin rate card, keyed to a catalog item. When goods move one hop
 * down the chain the SELLER earns transfer_value × (the % for the seller's level).
 * Area Dealer is a buyer-only leaf, so it has no rate column. Superadmin/HQ earns
 * nothing (no level row applies). One row per catalog product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_margins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_product_id')->unique()
                ->constrained('catalog_products')->cascadeOnDelete();
            $table->decimal('zonal_pct', 8, 3)->default(0);
            $table->decimal('district_pct', 8, 3)->default(0);
            $table->decimal('taluk_pct', 8, 3)->default(0);
            $table->decimal('wholesaler_pct', 8, 3)->default(0);
            $table->decimal('reseller_pct', 8, 3)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_margins');
    }
};
