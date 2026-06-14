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
        // MySQL-only: ENUM modification. SQLite (tests) stores enums as TEXT with no
        // constraint, so 'cash' is already accepted — nothing to alter.
        if (DB::getDriverName() !== 'mysql') {
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
