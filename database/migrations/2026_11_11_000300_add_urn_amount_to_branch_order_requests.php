<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-11: because of the per-order limit a branch sometimes splits ONE payment over
 * two orders. The requester states the amount paid on the URN; the same branch may then use
 * that URN again until the orders together reach the amount paid — never beyond, and never
 * from another branch (BranchOrderService::urnProblem).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->decimal('urn_amount', 15, 2)->nullable()->after('urn_no');
        });
    }

    public function down(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->dropColumn('urn_amount');
        });
    }
};
