<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile-app revert 2026-08-11: every e-com product showed "Out of stock" because
 * stock_qty defaulted to 0 and nothing maintains it (jewellery here is priced at
 * the live rate and fulfilled by the branch — checkout never consumes a count).
 * NULL now means "not tracked → always available"; an explicit 0 set by admin
 * still reads as genuinely out of stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function ($table) {
            $table->decimal('stock_qty', 12, 4)->nullable()->default(null)->change();
        });

        // Existing 0s were never a deliberate "out of stock" — they were the old default.
        DB::table('products')->where('stock_qty', 0)->update(['stock_qty' => null]);
    }

    public function down(): void
    {
        DB::table('products')->whereNull('stock_qty')->update(['stock_qty' => 0]);
        Schema::table('products', function ($table) {
            $table->decimal('stock_qty', 12, 4)->default(0)->change();
        });
    }
};
