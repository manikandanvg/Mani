<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (Wallet & Scan-Pay) — the digi-gold wallet becomes a real, spendable pot.
 *
 *  - digi_gold_txns:      the gram ledger behind member_wallets.digi_gold_grams. Every
 *                         credit/debit lands here with the rate snapshot and the running
 *                         balance, so the balance column is always reconstructible.
 *  - digi_gold_purchases: online buys (Razorpay). Row is created before the gateway
 *                         order; grams credit only on verified payment.
 *  - scan_payments:       member → dealer Scan & Pay through the branch L-BOX static QR.
 *                         Debits the member's gold at the live rate, credits the branch
 *                         Digi cash wallet, and the box announces the payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digi_gold_txns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('type', 8);                        // credit | debit
            $table->decimal('grams', 12, 4);
            $table->decimal('rate', 12, 2);                   // INR per gram at the moment of the txn
            $table->decimal('value', 15, 2);                  // grams × rate (INR)
            $table->string('source', 24);                     // buy | scan_pay | scan_pay_refund | admin_adjust
            $table->string('reference', 64)->nullable();      // e.g. purchase id, scan payment id
            $table->decimal('balance_after', 12, 4);          // running gold balance after this txn
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
        });

        Schema::create('digi_gold_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);                 // INR paid
            $table->decimal('rate', 12, 2);                   // INR per gram quoted at buy time
            $table->decimal('grams', 12, 4);                  // grams credited on payment
            $table->string('razorpay_order_id', 64)->nullable()->index();
            $table->string('razorpay_payment_id', 64)->nullable();
            $table->string('status', 12)->default('created'); // created | paid | failed
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });

        Schema::create('scan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->decimal('amount', 12, 2);                 // INR value received by the branch
            $table->decimal('rate', 12, 2);                   // INR per gram at pay time
            $table->decimal('grams', 12, 4);                  // gold debited from the member
            $table->string('status', 12)->default('paid');    // paid | cancelled
            $table->string('note')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_payments');
        Schema::dropIfExists('digi_gold_purchases');
        Schema::dropIfExists('digi_gold_txns');
    }
};
