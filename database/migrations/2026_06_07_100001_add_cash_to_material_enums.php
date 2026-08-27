<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Cash" is a real stock type in the legacy system — every branch holds a CASH stock
 * balance (weight = rupee value, rate = 1). Billing it under a Digital/RD plan opens a
 * contract. Add 'cash' to the material enums so it can be catalogued and stocked.
 */
return new class extends Migration {
    public function up(): void
    {
        // MySQL: in-place ENUM modification. Other drivers (SQLite in tests) enforce
        // enums as CHECK constraints since Laravel 11, so the column is rebuilt via the
        // schema builder instead — 'cash' must be insertable there too (2026-08-26).
        if (DB::getDriverName() !== 'mysql') {
            \Illuminate\Support\Facades\Schema::table('catalog_products', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->enum('material', ['gold', 'silver', 'vessel', 'cash'])->default('gold')->change();
            });
            \Illuminate\Support\Facades\Schema::table('categories', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->enum('material', ['gold', 'silver', 'vessel', 'accessory', 'diamond', 'other', 'cash'])->default('gold')->change();
            });

            return;
        }
        DB::statement("ALTER TABLE catalog_products MODIFY material ENUM('gold','silver','vessel','cash') NOT NULL DEFAULT 'gold'");
        DB::statement("ALTER TABLE categories MODIFY material ENUM('gold','silver','vessel','accessory','diamond','other','cash') NOT NULL DEFAULT 'gold'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE catalog_products MODIFY material ENUM('gold','silver','vessel') NOT NULL DEFAULT 'gold'");
        DB::statement("ALTER TABLE categories MODIFY material ENUM('gold','silver','vessel','accessory','diamond','other') NOT NULL DEFAULT 'gold'");
    }
};
