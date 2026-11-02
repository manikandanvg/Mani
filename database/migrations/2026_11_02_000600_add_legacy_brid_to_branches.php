<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-02: 1,038 legacy branches share the name "LORD JEWELLER" and the city
 * "SIVAKASI" (every area dealer / reseller / wholesaler is registered under the HQ name),
 * so an importer keyed on name + city folded 1,037 of them into Head Office. A branch's
 * identity is its legacy `tbl_branches.brid` from now on — stored here, unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedInteger('legacy_brid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['legacy_brid']);
            $table->dropColumn('legacy_brid');
        });
    }
};
