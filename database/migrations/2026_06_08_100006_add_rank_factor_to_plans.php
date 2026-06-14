<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ranking-BV configuration per plan (legacy rabvreset rule, made data-driven):
 *  - counts_for_rank = false  → plan contributes nothing to ranking BV (legacy 206/210).
 *  - rank_factor (nullable)   → explicit factor override (legacy 211 = 0.10).
 *  - otherwise the factor is derived from allocation_pct (100% → ×1, else the remainder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('counts_for_rank')->default(true)->after('allocation_pct');
            $table->decimal('rank_factor', 6, 4)->nullable()->after('counts_for_rank');
        });

        // Backfill the legacy special cases by plan code (Pxxx = legacy planid).
        DB::table('plans')->whereIn('code', ['P206', 'P210'])->update(['counts_for_rank' => false]);
        DB::table('plans')->where('code', 'P211')->update(['rank_factor' => 0.10]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['counts_for_rank', 'rank_factor']);
        });
    }
};
