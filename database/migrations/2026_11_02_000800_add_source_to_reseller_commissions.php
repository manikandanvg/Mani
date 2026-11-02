<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-02: the 62,450 legacy dealer-margin rows (₹4.78 cr, status "passed") are
 * HISTORY — they were settled in legacy over the years; the dealer's unpaid balance is the
 * legacy accrued figure that now waits on Pending Billing Margin. They must never appear in
 * the Commission Approval queue, so they are tagged source = legacy. Rows the new system
 * created since go-live keep source = new (their created_at is the real clock; the import
 * stamped created_at with the legacy bill date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_commissions', function (Blueprint $table) {
            $table->string('source', 20)->default('new')->after('status')->index();
        });
        DB::table('reseller_commissions')->where('created_at', '<', '2026-11-02 00:00:00')->update(['source' => 'legacy']);
    }

    public function down(): void
    {
        Schema::table('reseller_commissions', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
