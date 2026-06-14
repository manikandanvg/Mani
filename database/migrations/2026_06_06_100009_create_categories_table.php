<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->json('name');                       // translatable
            $table->string('slug', 140)->unique();
            // which product world this category belongs to (the two are unrelated):
            //   ecommerce = storefront catalog, trade = gold/silver/vessel stock items
            $table->enum('domain', ['ecommerce', 'trade'])->default('ecommerce');
            $table->enum('material', ['gold', 'silver', 'vessel', 'accessory', 'diamond', 'other'])->default('gold');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete(); // sub-category
            $table->string('image_path', 255)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
