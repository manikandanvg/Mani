<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razorpay payment records for storefront orders. One row per checkout attempt; the
 * signature is verified server-side before an order is marked paid.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('razorpay_order_id', 64)->nullable()->after('payment_status');
            $table->timestamp('paid_at')->nullable()->after('razorpay_order_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('gateway', 20)->default('razorpay');
            $table->string('razorpay_order_id', 64)->nullable();
            $table->string('razorpay_payment_id', 64)->nullable();
            $table->string('razorpay_signature', 128)->nullable();
            $table->decimal('amount', 15, 2)->default(0);   // charged amount (INR)
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('created'); // created | paid | failed
            $table->string('method', 30)->nullable();          // card | upi | netbanking …
            $table->string('email', 150)->nullable();
            $table->string('contact', 20)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('razorpay_order_id');
            $table->index('razorpay_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['razorpay_order_id', 'paid_at']);
        });
    }
};
