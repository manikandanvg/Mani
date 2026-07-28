<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan schema v2 (board sheet 2026-07-28, Scheme_new.csv):
 *  - allocation splits into BV vs contract halves (allocation_bv + allocation_cont).
 *  - rank_factor becomes an explicit 0-100 percentage on every row (was a nullable
 *    ×-factor with a derive-from-allocation fallback).
 *  - dealership hierarchy (hid), per-transaction max_value, per-reseller monthly
 *    billing cap (max_sales_value), redeem/contract/invoice flags, RD renewal
 *    margin, redeem-time useraccess flag and a free-text settlement note.
 *  - plans.gm_margin / stock_trans_margin dropped: both live per catalog product /
 *    per branch since the 2026-06-09 split and were dead on plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        // hasColumn guards: MySQL DDL is non-transactional, so a mid-way failure must
        // be resumable without "column already exists / not found" errors.
        if (Schema::hasColumn('plans', 'allocation_pct')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->renameColumn('allocation_pct', 'allocation_bv');
            });
        }

        if (! Schema::hasColumn('plans', 'hid')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->unsignedInteger('hid')->nullable()->after('code');      // dealership hierarchy order (1 = Regional)
                $table->decimal('max_value', 15, 2)->nullable()->after('min_value');
                $table->decimal('max_sales_value', 15, 2)->nullable()->after('max_value'); // reseller billing cap / month (INR)
                $table->decimal('allocation_cont', 6, 3)->default(0)->after('allocation_bv');
                $table->boolean('is_redeem')->default(false)->after('allocation_cont');
                $table->boolean('is_contract')->default(false)->after('is_redeem');
                $table->boolean('is_invoice')->default(false)->after('is_contract');
                $table->decimal('renewal_margin', 8, 3)->default(0)->after('billing_margin');
                $table->boolean('useraccess')->default(false)->after('is_active');
                $table->string('settlement')->nullable()->after('useraccess');
            });
        }

        // Widen BEFORE backfilling: the old decimal(6,4) cannot hold 100.
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('rank_factor', 6, 2)->nullable()->default(100)->change();
        });

        // Old semantics: nullable factor (P211 = 0.10), else derived from allocation
        // (100% → ×1, otherwise the remainder). Bake that into an explicit percentage.
        DB::statement(<<<'SQL'
            UPDATE plans SET rank_factor = COALESCE(
                rank_factor * 100,
                CASE WHEN allocation_bv >= 100 THEN 100 ELSE 100 - allocation_bv END
            )
        SQL);

        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('rank_factor', 6, 2)->default(100)->change();
        });

        if (Schema::hasColumn('plans', 'gm_margin')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn(['gm_margin', 'stock_trans_margin']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('gm_margin', 8, 3)->default(0);
            $table->decimal('stock_trans_margin', 8, 3)->default(0);
            $table->dropColumn([
                'hid', 'max_value', 'max_sales_value', 'allocation_cont',
                'is_redeem', 'is_contract', 'is_invoice',
                'renewal_margin', 'useraccess', 'settlement',
            ]);
        });

        DB::statement('UPDATE plans SET rank_factor = rank_factor / 100');

        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('rank_factor', 6, 4)->nullable()->default(null)->change();
            $table->renameColumn('allocation_bv', 'allocation_pct');
        });
    }
};
