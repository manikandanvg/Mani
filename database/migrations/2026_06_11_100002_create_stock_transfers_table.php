<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock-transfer event ledger — one row per goods movement from a source (seller)
 * branch to a destination (buyer) branch, recorded the moment it happens. The
 * stock-transfer margin earned by the seller on that hop is captured inline
 * (rate snapshot + amount) so the audit row is self-contained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 40)->nullable()->unique();
            $table->date('transfer_date');
            $table->foreignId('source_branch_id')->constrained('branches');       // seller
            $table->foreignId('destination_branch_id')->constrained('branches');   // buyer
            $table->string('seller_level', 20)->nullable();                        // snapshot of source level
            $table->foreignId('catalog_product_id')->nullable()->constrained('catalog_products')->nullOnDelete();
            $table->enum('material', ['gold', 'silver', 'vessel'])->default('gold');
            $table->decimal('weight', 12, 4)->default(0);      // grams (gold/silver)
            $table->decimal('quantity', 12, 3)->default(0);    // units (vessel)
            $table->decimal('unit_rate', 15, 2)->default(0);   // metal ₹/g or ₹/unit
            $table->decimal('transfer_value', 15, 2)->default(0);
            $table->decimal('margin_pct', 8, 3)->default(0);
            $table->decimal('margin_amount', 15, 2)->default(0);
            $table->enum('status', ['pending', 'passed'])->default('passed');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_branch_id', 'transfer_date']);
            $table->index('destination_branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
