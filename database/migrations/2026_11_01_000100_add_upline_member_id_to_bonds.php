<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy parity (docs/CUTOFF-PLAN.md): the level-1 upline of a contract is FROZEN at sale
 * (legacy tbl_bond.uplineuserid). The monthly cut-off pays level 1 to this member and walks
 * levels 2–5 up from there, even when the distributor was re-mapped later. Imported from
 * legacy; set by the Sales screen for new contracts; null = fall back to the current upline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonds', function (Blueprint $table) {
            $table->unsignedBigInteger('upline_member_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('bonds', function (Blueprint $table) {
            $table->dropColumn('upline_member_id');
        });
    }
};
