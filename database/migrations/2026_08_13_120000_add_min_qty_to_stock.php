<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimum stock level per branch per catalog product (board 2026-08-13).
 * When quantity falls to or below min_qty the row is flagged low so the branch
 * reorders before it runs out. NULL = no minimum set (never flagged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock', function (Blueprint $table) {
            $table->decimal('min_qty', 15, 4)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock', fn (Blueprint $table) => $table->dropColumn('min_qty'));
    }
};
