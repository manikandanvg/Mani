<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A distributor's stock order to Head Office (legacy tbl_brorderrequest + tbl_br_purchase_cart).
 * Submitted PENDING; on HQ approval the ordered items are added to the branch's stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_order_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 40)->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();   // destination branch
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('no_of_items')->default(0);
            $table->decimal('cross_total', 15, 2)->default(0);
            $table->decimal('gst_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->enum('payment_type', ['cash', 'cheque', 'online', 'others'])->default('cash');
            $table->text('payment_remarks')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('branch_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_request_id')->constrained('branch_order_requests')->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->nullable()->constrained('catalog_products')->nullOnDelete();
            $table->string('material', 20)->default('gold');
            $table->string('description', 200)->nullable();
            $table->decimal('weight', 15, 4)->default(0);       // grams, or rupee value for cash
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('making_charge_pct', 6, 3)->default(0);
            $table->decimal('wastage_charge_pct', 6, 3)->default(0);
            $table->decimal('hallmark_charge', 12, 2)->default(0);
            $table->decimal('gst_pct', 6, 3)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_order_lines');
        Schema::dropIfExists('branch_order_requests');
    }
};
