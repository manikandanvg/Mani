<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board corrections to the Customize Order Form (2026-08-26, round 2):
 *
 *  - Charges come from the Charge Brackets (weight slab → making % + wastage %), not a
 *    typed per-gram cost. Lines reuse making_charge_pct / wastage_charge_pct.
 *  - The customer may be NEW (details captured on the order only — never saved as a
 *    member) or EXISTING (member_id; coins can be applied).
 *  - SPLIT payment: an amount from the branch's cash stock + an amount from the branch
 *    wallet (+ coin credit) must cover the total in full before the order proceeds.
 *    Gold / silver metal payment options are withdrawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('branch_id')->constrained('members')->nullOnDelete();
            $table->json('customer_details')->nullable()->after('member_id');
            $table->decimal('pay_cash', 15, 2)->default(0)->after('coin_credit');
            $table->decimal('pay_wallet', 15, 2)->default(0)->after('pay_cash');
            $table->dropColumn('metal_grams');
        });
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->enum('payment_type', ['cash', 'cheque', 'online', 'digi_cash', 'split', 'others'])
                ->default('cash')->change();
        });
    }

    public function down(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->enum('payment_type', ['cash', 'cheque', 'online', 'digi_cash', 'gold', 'silver', 'others'])
                ->default('cash')->change();
        });
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->decimal('metal_grams', 12, 4)->nullable();
            $table->dropColumn(['pay_cash', 'pay_wallet', 'customer_details']);
            $table->dropConstrainedForeignId('member_id');
        });
    }
};
