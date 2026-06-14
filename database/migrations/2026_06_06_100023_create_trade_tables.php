<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 2 Trade: purchases (at live rates) -> accumulating branch stock, with a
// movement ledger. Stock is scoped to the buying admin's branch.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('ref_no', 40)->unique();
            $table->date('purchase_date');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('gold_rate', 12, 2)->default(0);   // live rate captured at purchase
            $table->decimal('silver_rate', 12, 2)->default(0);
            $table->decimal('gross_total', 15, 2)->default(0);
            $table->decimal('gst_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->enum('payment_type', ['cash', 'online', 'cheque'])->default('cash');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'purchase_date']);
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->nullable()->constrained('catalog_products')->nullOnDelete();
            $table->enum('material', ['gold', 'silver', 'vessel'])->default('gold');
            $table->string('description', 200)->nullable();
            $table->decimal('weight', 12, 4)->default(0);       // grams or units
            $table->string('purity', 12)->nullable();
            $table->decimal('rate', 12, 2)->default(0);          // per gram at purchase
            $table->decimal('making_charge_pct', 6, 3)->default(0);
            $table->decimal('wastage_charge_pct', 6, 3)->default(0);
            $table->decimal('hallmark_charge', 12, 2)->default(0);
            $table->decimal('gst_pct', 6, 3)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
        });

        // Accumulated stock per branch per catalog product (20 + 100 = 120).
        Schema::create('stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->decimal('quantity', 15, 4)->default(0);     // accumulated weight/units
            $table->string('purity', 12)->nullable();
            $table->decimal('last_rate', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'catalog_product_id']);
        });

        // Every increment/decrement (purchase, sale, adjustment, transfer) for audit.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->enum('type', ['purchase', 'sale', 'adjustment', 'transfer'])->default('purchase');
            $table->decimal('qty_change', 15, 4);                // +in / -out
            $table->decimal('balance_after', 15, 4)->nullable();
            $table->string('ref_type', 40)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->date('moved_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'catalog_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
    }
};
