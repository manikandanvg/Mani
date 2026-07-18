<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CBC entries join the frozen-currency policy: `worth` stays INR base, and the
 * earner's currency + INR rate are frozen at ISSUE time (they were previously
 * looked up at approval time — a moving rate). Defaults keep India-only
 * behaviour byte-for-byte unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbc_entries', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('INR');
            $table->decimal('fx_rate', 18, 6)->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('cbc_entries', fn (Blueprint $table) => $table->dropColumn(['currency_code', 'fx_rate']));
    }
};
