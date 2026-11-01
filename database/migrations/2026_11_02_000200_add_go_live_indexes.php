<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live 2026-11-01: the legacy volumes (642k ledger rows, 162k coupons, 30k contracts)
 * are filtered by date / status on Commission Approval, Payout Runs, the cut-off engine and
 * the Settlements page. Plain indexes on those columns — nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = function (string $table, array $cols, string $name) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                return;
            }
            Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
        };

        $add('commission_ledger', ['earned_on'], 'cl_earned_on_idx');
        $add('commission_ledger', ['type', 'status', 'earned_on'], 'cl_type_status_earned_idx');
        $add('cbc_entries', ['cbc_date'], 'cbc_date_idx');
        $add('cbc_entries', ['status', 'cbc_date'], 'cbc_status_date_idx');
        $add('bonds', ['bond_date'], 'bonds_bond_date_idx');
        $add('bonds', ['status', 'bond_date'], 'bonds_status_date_idx');
        $add('member_contracts', ['status', 'end_date'], 'mc_status_end_idx');
        $add('digi_queue', ['delivery_status', 'route'], 'dq_status_route_idx');
        $add('reseller_commissions', ['status', 'com_type_id'], 'rc_status_type_idx');
    }

    public function down(): void
    {
        $drop = function (string $table, string $name) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        };

        $drop('commission_ledger', 'cl_earned_on_idx');
        $drop('commission_ledger', 'cl_type_status_earned_idx');
        $drop('cbc_entries', 'cbc_date_idx');
        $drop('cbc_entries', 'cbc_status_date_idx');
        $drop('bonds', 'bonds_bond_date_idx');
        $drop('bonds', 'bonds_status_date_idx');
        $drop('member_contracts', 'mc_status_end_idx');
        $drop('digi_queue', 'dq_status_route_idx');
        $drop('reseller_commissions', 'rc_status_type_idx');
    }
};
