<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-13 (final confirmation): a redemption is calculated at the FROZEN price — the
 * gold / silver price of the BILLED date, each item at its own metal's price — and the bill also
 * shows the LIVE market price of the redemption day.
 *
 *  1. redemption_invoices gets the frozen prices used and their date, next to gold_rate /
 *     silver_rate (which now hold the live market price printed on top of the bill).
 *  2. Silver QRs carried over from the old system were staged with the GOLD rate as their frozen
 *     rate (₹15,465/g on a silver QR): the pending ones get the silver rate of their bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemption_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('redemption_invoices', 'frozen_gold_rate')) {
                $table->decimal('frozen_gold_rate', 14, 2)->nullable()->after('silver_rate');
                $table->decimal('frozen_silver_rate', 14, 2)->nullable()->after('frozen_gold_rate');
                $table->date('frozen_on')->nullable()->after('frozen_silver_rate');
            }
        });

        DB::table('redeemable_qrs')
            ->where('qr_mode', 'silver')->where('status', 'pending')->where('frozen_rate', '>', 0)->whereNotNull('invoice_no')
            ->orderBy('id')
            ->chunkById(500, function ($qrs) {
                $sales = DB::table('sales_invoices')->whereIn('invoice_no', $qrs->pluck('invoice_no')->unique())
                    ->get(['invoice_no', 'gold_rate', 'silver_rate'])->keyBy('invoice_no');
                foreach ($qrs as $qr) {
                    $sale = $sales->get($qr->invoice_no);
                    if ($sale && (float) $sale->silver_rate > 0 && abs((float) $qr->frozen_rate - (float) $sale->gold_rate) < 1) {
                        DB::table('redeemable_qrs')->where('id', $qr->id)->update(['frozen_rate' => (float) $sale->silver_rate]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('redemption_invoices', function (Blueprint $table) {
            $table->dropColumn(['frozen_gold_rate', 'frozen_silver_rate', 'frozen_on']);
        });
    }
};
