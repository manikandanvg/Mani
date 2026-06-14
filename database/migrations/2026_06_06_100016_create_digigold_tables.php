<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Digi-gold: the settlement rail. orders (buy/credit), queue (QR redemption),
// withdrawals (liquidate).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('digi_orders', function (Blueprint $table) {
            $table->id();
            $table->string('ref_no', 40)->unique();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->enum('source', ['IC', 'SP', 'LEVEL', 'BM'])->default('SP');
            $table->decimal('gold_wt', 12, 4);
            $table->decimal('value', 15, 2);
            $table->decimal('rate_on_date', 12, 2);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->index('member_id');
        });

        Schema::create('digi_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('qr_code', 40)->unique();
            $table->enum('qr_mode', ['cash', 'gold', 'silver'])->default('cash');
            $table->decimal('gram_worth', 12, 4)->nullable();
            $table->decimal('cash_worth', 15, 2)->nullable();
            $table->string('reference', 40)->nullable();
            $table->string('route', 40)->nullable();
            $table->enum('delivery_status', ['pending', 'redeemed'])->default('pending');
            $table->boolean('qr_sent')->default(false);
            $table->foreignId('redeem_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('digi_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->decimal('gold_wt', 12, 4);
            $table->decimal('worth', 15, 2);
            $table->enum('status', ['pending', 'approved', 'delivered'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digi_withdrawals');
        Schema::dropIfExists('digi_queue');
        Schema::dropIfExists('digi_orders');
    }
};
