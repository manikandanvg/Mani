<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-04: the imported legacy redemption invoices offered "Raise restock order" although
 * most dealers had already bought that stock from HQ in legacy (tbl_qrsalesinvoice.paymenttype =
 * ORDERED / APPROVED is the legacy restock-order state; PENDING = never ordered). Keep that state
 * on the row and stop offering a second restock for the ordered/approved ones.
 *
 * Also repairs rows the first import run wrote on live (legacy state had gone into payment_mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemption_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('redemption_invoices', 'legacy_restock')) {
                $table->string('legacy_restock', 12)->nullable()->after('payment_mode')
                    ->comment('legacy tbl_qrsalesinvoice.paymenttype: ordered | approved | pending');
            }
        });

        // imported rows carry the numeric legacy estid as invoice_no (new-system ones are RDM-…)
        foreach (['ordered', 'approved', 'pending'] as $state) {
            DB::table('redemption_invoices')
                ->whereRaw("invoice_no NOT LIKE 'RDM-%'")
                ->where('payment_mode', $state)
                ->whereNull('legacy_restock')
                ->update(['legacy_restock' => $state, 'payment_mode' => 'passed']);
        }
    }

    public function down(): void
    {
        Schema::table('redemption_invoices', function (Blueprint $table) {
            $table->dropColumn('legacy_restock');
        });
    }
};
