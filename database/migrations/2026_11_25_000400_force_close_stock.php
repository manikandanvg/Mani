<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-25 (final decision): stock recovered by "Force Close(Old)" goes to Head Office,
 * never back to the branch. Every such movement is entered here — the register behind
 * Report → Other Report → "Force Close stock":
 *
 *   bill_stock   the stock the closed bill had sold            issuing branch → Head Office
 *   renewal      the cash stock its Gold Saving renewals took   collecting branch → Head Office
 *   charge       what was already paid out / redeemed, deducted from the issuing branch's cash stock
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('force_close_stock')) {
            return;
        }
        Schema::create('force_close_stock', function (Blueprint $table) {
            $table->id();
            $table->date('moved_on')->index();
            $table->string('kind', 20)->index();
            $table->unsignedBigInteger('from_branch_id')->nullable()->index();
            $table->unsignedBigInteger('to_branch_id')->nullable();
            $table->unsignedBigInteger('catalog_product_id')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->string('invoice_no', 60)->nullable()->index();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('member_code', 40)->nullable();
            $table->string('member_name', 200)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('force_close_stock');
    }
};
