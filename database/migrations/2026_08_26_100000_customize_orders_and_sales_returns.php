<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board batch 2026-08-26 — "Customize Order Form" + Sales Returns.
 *
 * 1) branch_order_requests: a distributor can now send a CUSTOMISED order (bespoke
 *    gold/silver pieces priced live + margin + cost + GST) to its supplier. New payment
 *    modes gold / silver (metal handed over) join cash and the branch wallet (digi_cash).
 *    A customer's accumulated RD 100 mg coins can be applied to the order — recorded as
 *    a sales return (sales_return_id) whose value is `coin_credit`; `paid_amount` is the
 *    balance settled by the chosen payment mode (must equal grand_total − coin_credit).
 *
 * 2) branch_order_lines: per-gram price components for customised lines
 *    (unit_price = live rate + margin_per_g + cost_per_g; GST on top).
 *
 * 3) branch_order_attachments.disk: receipts now live on the PRIVATE disk and are
 *    streamed through an auth-guarded route — no dependence on the public/storage
 *    symlink (the live 404 on /storage/order-receipts/…).
 *
 * 4) sales_returns: coins / metal a customer hands back to a distributor — collected at
 *    a set date & time, added to branch stock, then relayed to the supplier with the order.
 *
 * 5) stock_movements.type gains 'sales_return'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('catalog_product_id')->nullable()->constrained('catalog_products')->nullOnDelete();
            $table->string('material', 20)->default('gold');
            $table->decimal('quantity', 12, 4)->default(0);      // pieces (coins)
            $table->decimal('grams', 12, 4)->default(0);         // total metal weight
            $table->decimal('rate', 12, 2)->default(0);          // ₹ per gram used to value it
            $table->decimal('credit_value', 15, 2)->default(0);  // grams × rate
            $table->dateTime('collect_on')->nullable();          // agreed collection date & time
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('relayed_at')->nullable();         // passed on to the supplier
            $table->string('status', 20)->default('pending');    // pending | collected | relayed | cancelled
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->enum('payment_type', ['cash', 'cheque', 'online', 'digi_cash', 'gold', 'silver', 'others'])
                ->default('cash')->change();
            $table->decimal('coin_credit', 15, 2)->default(0)->after('grand_total');
            $table->decimal('paid_amount', 15, 2)->default(0)->after('coin_credit');
            $table->decimal('metal_grams', 12, 4)->nullable()->after('paid_amount');
            $table->foreignId('sales_return_id')->nullable()->after('metal_grams')
                ->constrained('sales_returns')->nullOnDelete();
        });

        Schema::table('branch_order_lines', function (Blueprint $table) {
            $table->decimal('margin_per_g', 12, 2)->default(0)->after('rate');
            $table->decimal('cost_per_g', 12, 2)->default(0)->after('margin_per_g');
            $table->decimal('unit_price', 12, 2)->default(0)->after('cost_per_g');
        });

        Schema::table('branch_order_attachments', function (Blueprint $table) {
            $table->string('disk', 20)->default('public')->after('path');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'adjustment', 'transfer', 'sales_return'])
                ->default('purchase')->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'adjustment', 'transfer'])->default('purchase')->change();
        });
        Schema::table('branch_order_attachments', fn (Blueprint $table) => $table->dropColumn('disk'));
        Schema::table('branch_order_lines', fn (Blueprint $table) => $table->dropColumn(['margin_per_g', 'cost_per_g', 'unit_price']));
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_return_id');
            $table->dropColumn(['coin_credit', 'paid_amount', 'metal_grams']);
            $table->enum('payment_type', ['cash', 'cheque', 'online', 'digi_cash', 'others'])->default('cash')->change();
        });
        Schema::dropIfExists('sales_returns');
    }
};
