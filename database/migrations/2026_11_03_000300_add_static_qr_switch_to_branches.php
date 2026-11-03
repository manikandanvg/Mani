<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * User 2026-11-03: some resellers / area dealers DO need the static L-BOX QR. The level rule
 * (Branch::NO_PAYMENT_QR_LEVELS) stays the default; admin overrides one branch on its edit form:
 * NULL = by level, 'on' = always show the QR, 'off' = never.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('static_qr', 3)->nullable()->after('payment_qr_path');
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('static_qr'));
    }
};
