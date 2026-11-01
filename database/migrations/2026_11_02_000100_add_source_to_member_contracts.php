<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live 2026-11-01: legacy contracts (tbl_bond) are carried into member_contracts by
 * `contracts:backfill-legacy` so the Settlements screen can close / settle them. They are
 * tagged source = legacy: the automatic settlement engine (SettlementService) leaves them
 * alone — Head Office decides each one by hand on the Settlements page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_contracts', function (Blueprint $table) {
            $table->string('source', 16)->default('system')->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('member_contracts', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
