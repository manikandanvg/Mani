<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each sale line to its catalog item, so the redeem flow (esp. the P206/P212 modes
 * that rebuild the cart from the original sale's lines) can recover material / HSN / rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->foreignId('catalog_product_id')->nullable()->after('product_id')
                ->constrained('catalog_products')->nullOnDelete();
            $table->string('material', 20)->nullable()->after('catalog_product_id');
            $table->decimal('rate', 15, 2)->default(0)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_product_id');
            $table->dropColumn(['material', 'rate']);
        });
    }
};
