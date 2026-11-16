<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-16: ONE restock order per redemption invoice, whichever system raised it.
 * `redemption_invoice_id` links a restock order (new-system "redemption" or old-system "legacy")
 * to the redemption invoice it restores; the UNIQUE index refuses a second order for the same
 * invoice at the database. Old-system orders are linked by `legacy:link-restock-orders`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branch_order_requests', 'redemption_invoice_id')) {
            Schema::table('branch_order_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('redemption_invoice_id')->nullable()->after('source_ref');
            });
        }

        // new-system restock orders already carry the invoice id in source_ref — one per invoice
        $rows = DB::table('branch_order_requests')->where('source', 'redemption')->whereNotNull('source_ref')
            ->whereNull('redemption_invoice_id')->orderBy('id')->get(['id', 'source_ref']);
        $seen = DB::table('branch_order_requests')->whereNotNull('redemption_invoice_id')->pluck('redemption_invoice_id')->flip()->all();
        foreach ($rows as $r) {
            if (isset($seen[$r->source_ref])) {
                continue;
            }
            $seen[$r->source_ref] = true;
            DB::table('branch_order_requests')->where('id', $r->id)->update(['redemption_invoice_id' => $r->source_ref]);
        }

        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->unique('redemption_invoice_id', 'bor_redemption_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->dropUnique('bor_redemption_invoice_unique');
            $table->dropColumn('redemption_invoice_id');
        });
    }
};
