<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 1.4.2 Trade catalog: Gold / Silver / Vessel item masters. These have NO fixed
// price — they are valued at the live metal rate at billing time. Purchased into
// branch stock (Trade) and sold via the admin Sales module. Separate from the
// storefront `products` table.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->json('name');                           // translatable
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->enum('material', ['gold', 'silver', 'vessel'])->default('gold');
            $table->decimal('default_weight', 12, 4)->nullable();   // grams (per piece)
            $table->string('default_purity', 12)->nullable();       // 22K, 916, 925...
            $table->decimal('making_charge_pct', 6, 3)->default(0);
            $table->decimal('wastage_charge_pct', 6, 3)->default(0);
            $table->decimal('hallmark_charge', 12, 2)->default(0);
            $table->decimal('gst_pct', 6, 3)->default(3);
            $table->string('image_path', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
