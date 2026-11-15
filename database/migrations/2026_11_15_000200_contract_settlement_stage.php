<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-15: on a gold-QR contract the admin CHOOSES what is being settled —
 * the interim settlement or the final one (which closes the contract). The choice is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_settlements', function (Blueprint $t) {
            $t->string('stage', 8)->nullable()->after('mode');   // interim | final (null = the earlier wallet settlements)
        });
    }

    public function down(): void
    {
        Schema::table('contract_settlements', fn (Blueprint $t) => $t->dropColumn('stage'));
    }
};
