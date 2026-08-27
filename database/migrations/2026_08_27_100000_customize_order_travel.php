<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customized-order travel (board 2026-08-27):
 *
 *  - A customized order climbs the dealership ladder: the default supplier may FORWARD
 *    it to its own supplier, hop by hop, until it reaches HQ. Only HQ accepts (with a
 *    delivery date, coin pick-up date and an optional extra quote). `current_branch_id`
 *    = where the order / its goods currently sit; `branch_order_events` = the road map.
 *  - On accept HQ makes the pieces into its own stock; "Delivery" / "Forward" then move
 *    them back DOWN the same road, hop by hop, each seller earning transfer margin.
 *  - Custom pieces live in `stock` under two system catalog items ("Custom Order — Gold /
 *    Silver"); `stock.order_line_id` + `label` keep each piece tied to its order
 *    ("Gold 15 g necklace (ORD-000123)"). The unique key widens to include it.
 *  - The requesting Taluka finally bills each piece as a G10 material sale at the frozen
 *    price; HQ's extra quote is debited from its branch wallet at that moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: MySQL DDL is not transactional, so a half-applied run can be re-run.
        if (! Schema::hasTable('branch_order_events')) {
            Schema::create('branch_order_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_request_id')->constrained('branch_order_requests')->cascadeOnDelete();
                $table->string('action', 30);   // submitted | forwarded | rejected | accepted | delivered | coins_captured | billed
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();      // actor branch
                $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();   // where it went
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('note', 500)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['order_request_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('branch_order_requests', 'current_branch_id')) {
            Schema::table('branch_order_requests', function (Blueprint $table) {
                $table->foreignId('current_branch_id')->nullable()->after('branch_id')->constrained('branches')->nullOnDelete();
                $table->decimal('quote_extra', 15, 2)->default(0)->after('paid_amount');
                $table->timestamp('quote_debited_at')->nullable()->after('quote_extra');
                $table->date('delivery_date')->nullable()->after('quote_debited_at');
                $table->dateTime('coin_pickup_on')->nullable()->after('delivery_date');
                $table->timestamp('coin_captured_at')->nullable()->after('coin_pickup_on');
                $table->timestamp('delivered_at')->nullable()->after('coin_captured_at');
            });
        }
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled', 'in_transit', 'delivered', 'billed'])
                ->default('pending')->change();
        });

        if (! Schema::hasColumn('branch_order_lines', 'sales_invoice_id')) {
            Schema::table('branch_order_lines', function (Blueprint $table) {
                $table->foreignId('sales_invoice_id')->nullable()->after('line_total')->constrained('sales_invoices')->nullOnDelete();
                $table->timestamp('billed_at')->nullable()->after('sales_invoice_id');
            });
        }

        if (! Schema::hasColumn('catalog_products', 'is_custom_order')) {
            Schema::table('catalog_products', function (Blueprint $table) {
                $table->boolean('is_custom_order')->default(false)->after('is_active');
            });
        }

        if (! Schema::hasColumn('stock', 'order_line_id')) {
            // Add the wider unique key FIRST — on MySQL the old unique index also serves the
            // branch_id foreign key, which refuses to lose its last index.
            Schema::table('stock', function (Blueprint $table) {
                // 0 = ordinary stock row; otherwise the customized-order line this piece belongs to.
                $table->unsignedBigInteger('order_line_id')->default(0)->after('catalog_product_id');
                $table->string('label', 200)->nullable()->after('order_line_id');
                $table->unique(['branch_id', 'catalog_product_id', 'order_line_id'], 'stock_branch_product_line_unique');
            });
            Schema::table('stock', function (Blueprint $table) {
                $table->dropUnique(['branch_id', 'catalog_product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('stock', fn (Blueprint $table) => $table->unique(['branch_id', 'catalog_product_id']));
        Schema::table('stock', function (Blueprint $table) {
            $table->dropUnique('stock_branch_product_line_unique');
            $table->dropColumn(['order_line_id', 'label']);
        });
        Schema::table('catalog_products', fn (Blueprint $table) => $table->dropColumn('is_custom_order'));
        Schema::table('branch_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_invoice_id');
            $table->dropColumn('billed_at');
        });
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending')->change();
        });
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_branch_id');
            $table->dropColumn(['quote_extra', 'quote_debited_at', 'delivery_date', 'coin_pickup_on', 'coin_captured_at', 'delivered_at']);
        });
        Schema::dropIfExists('branch_order_events');
    }
};
