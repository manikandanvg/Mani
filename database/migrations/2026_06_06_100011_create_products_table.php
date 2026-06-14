<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1.4.1 E-COMMERCE products (storefront catalog). UNIQUE items, with NO link
        // to the trade/stock catalog used by the admin Sales module.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->json('name');                           // translatable
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->enum('material', ['gold', 'silver', 'accessory', 'diamond', 'other'])->default('gold');
            $table->decimal('weight', 12, 4)->nullable();   // grams
            $table->decimal('stone_weight', 12, 4)->nullable();
            $table->string('purity', 12)->nullable();       // 22K, 916, 925...
            $table->decimal('base_price', 15, 2)->nullable();
            $table->decimal('making_charge_pct', 6, 3)->nullable();
            $table->decimal('wastage_charge_pct', 6, 3)->nullable();
            $table->decimal('hallmark_charge', 12, 2)->nullable();
            $table->decimal('gst_pct', 6, 3)->nullable();
            $table->decimal('stock_qty', 12, 4)->default(0);
            $table->json('description')->nullable();        // translatable
            $table->boolean('is_featured')->default(false); // homepage highlight
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path', 255);
            $table->unsignedInteger('sort')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }
};
