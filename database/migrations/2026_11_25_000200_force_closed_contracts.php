<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-25: a force-closed contract stays on the Settlements page (member_contracts
 * status force_closed + the day) and its closure figures hang on that row.
 * For a database that already ran 2026_11_25_000100 in its first form — every step is guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('member_contracts', 'force_closed_on')) {
            Schema::table('member_contracts', function (Blueprint $table) {
                $table->date('force_closed_on')->nullable();
            });
        }
        if (Schema::hasTable('closure_settlements') && ! Schema::hasColumn('closure_settlements', 'member_contract_id')) {
            Schema::table('closure_settlements', function (Blueprint $table) {
                $table->unsignedBigInteger('member_contract_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // the columns belong to 2026_11_25_000100, which drops them
    }
};
