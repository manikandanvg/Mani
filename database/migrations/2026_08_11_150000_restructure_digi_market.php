<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-08-11 (mobile-app revert): Digi Gold becomes the two-metal
 * "Digi Market" — an INVESTMENT purse, not a spending purse.
 *
 *  - member_wallets.digi_silver_grams: silver held, beside the gold column.
 *  - digi_gold_txns.metal:             which purse a ledger row moved.
 *  - digi_gold_txns.fee:               platform fee (INR) withheld on a
 *                                      metal → cash-wallet transfer.
 *  - digi_gold_purchases.metal:        gold | silver.
 *  - digi_gold_purchases.funding:      online (Razorpay) | wallet (cash wallet).
 *
 * Scan & Pay is retired (metal can no longer be spent at a branch); its table
 * stays for history. Money now flows: cash wallet → metal → cash wallet only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_wallets', function (Blueprint $table) {
            $table->decimal('digi_silver_grams', 12, 4)->default(0)->after('digi_gold_grams');
        });

        Schema::table('digi_gold_txns', function (Blueprint $table) {
            $table->string('metal', 8)->default('gold')->after('member_id');
            $table->decimal('fee', 12, 2)->default(0)->after('value');
        });

        Schema::table('digi_gold_purchases', function (Blueprint $table) {
            $table->string('metal', 8)->default('gold')->after('member_id');
            $table->string('funding', 8)->default('online')->after('metal');
        });
    }

    public function down(): void
    {
        Schema::table('member_wallets', fn (Blueprint $t) => $t->dropColumn('digi_silver_grams'));
        Schema::table('digi_gold_txns', fn (Blueprint $t) => $t->dropColumn(['metal', 'fee']));
        Schema::table('digi_gold_purchases', fn (Blueprint $t) => $t->dropColumn(['metal', 'funding']));
    }
};
