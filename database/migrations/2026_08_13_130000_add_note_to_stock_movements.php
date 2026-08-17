<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reason text on a stock movement (board 2026-08-13). Head Office can correct a
 * branch's physical count from the Stock screen; the difference is logged as an
 * `adjustment` movement and this records WHY (count, damage, correction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('note', 200)->nullable()->after('ref_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('note'));
    }
};
