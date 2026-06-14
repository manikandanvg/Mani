<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make invoices and the margin ledger tax/currency-aware.
 *
 *  - sales_invoices / redemption_invoices: `tax_regime` (gst|vat|none) + `tax_total` so a
 *    foreign invoice can render a single VAT line (or none) instead of CGST/SGST. CGST/SGST
 *    are kept for GST branches.
 *  - reseller_commissions: `currency_code` + `fx_rate`. The stored `com_value` is the
 *    INR-base amount (so earning caps stay coherent across currencies); the wallet credit
 *    converts back to the earner's currency using the frozen fx_rate at approval.
 *
 * All defaults (gst / INR / 1.0) leave the India-only behaviour byte-for-byte unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_invoices', 'redemption_invoices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('tax_regime', 10)->default('gst');
                $table->decimal('tax_total', 18, 2)->default(0);
            });
        }

        Schema::table('reseller_commissions', function (Blueprint $table) {
            $table->char('currency_code', 3)->default('INR');
            $table->decimal('fx_rate', 18, 6)->default(1);
        });
    }

    public function down(): void
    {
        foreach (['sales_invoices', 'redemption_invoices'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['tax_regime', 'tax_total']));
        }
        Schema::table('reseller_commissions', fn (Blueprint $table) => $table->dropColumn(['currency_code', 'fx_rate']));
    }
};
