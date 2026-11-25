<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimisation (board 2026-11-25). Columns the screens look rows up by, which had no index —
 * every such lookup read the whole table (commission_ledger: 7 lakh rows per bill opened,
 * cancelled, force closed or tracked):
 *   bill number   commission_ledger / reseller_commissions / redeemable_qrs / bonds .invoice_no
 *   stock ledger  stock_movements (ref_type, ref_id) — "the movements of this order / redemption"
 */
return new class extends Migration
{
    protected const INDEXES = [
        ['commission_ledger', ['invoice_no'], 'cl_invoice_no_idx'],
        ['reseller_commissions', ['invoice_no'], 'rc_invoice_no_idx'],
        ['redeemable_qrs', ['invoice_no'], 'rq_invoice_no_idx'],
        ['bonds', ['invoice_no'], 'bonds_invoice_no_idx'],
        ['stock_movements', ['ref_type', 'ref_id'], 'sm_ref_idx'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $columns, $name]) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, , $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
