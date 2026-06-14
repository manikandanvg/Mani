<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Retail/POS invoices + lines, RD (gold-saving) installments, and weight-bracket
// making/wastage charges.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('charge_brackets', function (Blueprint $table) {
            $table->id();
            $table->enum('material', ['gold', 'silver'])->default('gold');
            $table->decimal('wt_from', 12, 4);
            $table->decimal('wt_to', 12, 4);
            $table->decimal('making_pct', 6, 3)->default(0);
            $table->decimal('wastage_pct', 6, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 40)->unique();
            $table->date('date');
            $table->foreignId('customer_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('customer_name', 200)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('cross_total', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('net_total', 15, 2)->default(0);
            $table->decimal('sgst', 15, 2)->default(0);
            $table->decimal('cgst', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('received', 15, 2)->default(0);
            $table->enum('payment_type', ['cash', 'cheque', 'upi', 'fx', 'online'])->default('cash');
            $table->string('payment_reference', 120)->nullable();
            // live metal rates captured for this bill (per gram, in branch currency)
            $table->decimal('gold_rate', 12, 2)->default(0);
            $table->decimal('silver_rate', 12, 2)->default(0);
            $table->decimal('diamond_rate', 12, 2)->default(0);
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->enum('remarks', ['SALES', 'TOPUP'])->default('SALES');
            $table->timestamps();
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 200)->nullable();
            $table->decimal('qty', 12, 4)->default(0);
            $table->decimal('making', 15, 2)->default(0);
            $table->decimal('wastage', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
        });

        Schema::create('rd_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bond_id')->constrained('bonds')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members');
            $table->date('paid_on');
            $table->decimal('value', 15, 2);
            $table->unsignedInteger('due_count')->default(0);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rd_entries');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('charge_brackets');
    }
};
