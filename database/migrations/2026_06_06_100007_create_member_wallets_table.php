<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Separate balances per member. Ledger-backed by commission_ledger / wallet_txns.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_wallets', function (Blueprint $table) {
            $table->foreignId('member_id')->primary()->constrained('members')->cascadeOnDelete();
            $table->decimal('cash_balance', 15, 2)->default(0);
            $table->decimal('coupon_balance', 15, 2)->default(0);
            $table->decimal('epin_balance', 15, 2)->default(0);
            $table->decimal('earning_total', 15, 2)->default(0);
            $table->decimal('withdrawn_total', 15, 2)->default(0);
            $table->decimal('digi_gold_grams', 12, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_wallets');
    }
};
