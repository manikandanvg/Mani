<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supply-chain hierarchy on branches. Each distributor branch sits at a LEVEL
 * (Lord/HQ → Zonal → District → Taluk → Wholesaler → Reseller; Area Dealer is a
 * free-sourcing leaf) and orders stock from ONE source branch above it. The admin
 * sets the source at /admin/branches; distributors request changes (see
 * branch_source_change_requests). Stock-transfer margin is earned by the SELLER
 * (source) on each hop — see stock_transfers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->enum('level', [
                'hq', 'zonal', 'district', 'taluk', 'wholesaler', 'reseller', 'area_dealer',
            ])->nullable()->after('name');
            // Who this branch orders stock FROM (the seller one hop up the chain).
            $table->foreignId('source_branch_id')->nullable()->after('level')
                ->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_branch_id');
            $table->dropColumn('level');
        });
    }
};
