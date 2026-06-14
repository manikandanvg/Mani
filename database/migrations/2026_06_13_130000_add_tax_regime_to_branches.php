<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch's tax regime, so a foreign branch can issue a correct local invoice:
 *   gst  — India: CGST + SGST split (the existing behaviour, the default).
 *   vat  — single VAT line at vat_pct (e.g. UK 20%).
 *   none — tax-exempt / out of scope (e.g. investment-gold relief).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('tax_regime', 10)->default('gst')->after('currency_code');
            $table->decimal('vat_pct', 6, 3)->default(0)->after('tax_regime');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['tax_regime', 'vat_pct']);
        });
    }
};
