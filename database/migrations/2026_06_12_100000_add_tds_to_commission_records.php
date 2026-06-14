<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TDS + service charge are now withheld when a commission is APPROVED (the redesigned
 * payout). The deductions and the net actually credited to the wallet are recorded on the
 * cash income records (IC/GAP in commission_ledger; the 4 margins in reseller_commissions).
 * CBC (coupon/EPIN) carries no TDS.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['commission_ledger', 'reseller_commissions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('tds', 15, 2)->default(0);
                $t->decimal('service_charge', 15, 2)->default(0);
                $t->decimal('net_amount', 15, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['commission_ledger', 'reseller_commissions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['tds', 'service_charge', 'net_amount']);
            });
        }
    }
};
