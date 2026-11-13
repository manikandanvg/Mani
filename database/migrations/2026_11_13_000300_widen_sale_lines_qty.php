<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Found by billing:proof (2026-11-13): a cash line stores the rupee amount as its quantity, and
 * decimal(12,4) tops out at ₹9,99,99,999.99 — a ₹10 crore bill (Regional Dealership × 2) could
 * not be saved. Widened to hold ₹99,999 crore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->decimal('qty', 12, 4)->default(0)->change();
        });
    }
};
