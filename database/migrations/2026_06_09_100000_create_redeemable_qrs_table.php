<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redeemable Stock QR — one per bond/contract. The customer scans it to redeem the
 * stock (gold / cash / silver) they are entitled to. Generated and WhatsApp-sent the
 * moment the contract is created; redeemed at a branch later (next phase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redeemable_qrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bond_id')->constrained('bonds')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('invoice_no', 40)->nullable();
            $table->string('qr_code', 40)->unique();         // the redeemable token (encoded in the PNG)
            $table->enum('qr_mode', ['gold', 'cash', 'silver'])->default('gold');
            $table->decimal('gram_worth', 12, 4)->nullable(); // grams of stock
            $table->decimal('cash_worth', 15, 2)->nullable(); // rupee worth
            $table->enum('status', ['pending', 'redeemed'])->default('pending');
            $table->boolean('qr_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('redeem_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redeemable_qrs');
    }
};
