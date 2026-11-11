<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-11: the Order Form asks for the payment's URN (bank transaction reference).
 * One URN pays for one order — a second request with the same number is refused
 * (BranchOrderService::assertUrnFree), and the number shows on the approval table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->string('urn_no', 60)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->dropColumn('urn_no');
        });
    }
};
