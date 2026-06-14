<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 4 storefront: CMS content (pages, blog/news, testimonials, FAQ) + the public
// e-commerce orders. All editorial text is translatable JSON for the multi-language site.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 140)->unique();      // about, services, privacy-policy, terms...
            $table->json('title');
            $table->json('body')->nullable();            // rich HTML per locale
            $table->string('group', 40)->default('page'); // page | policy
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['blog', 'news'])->default('blog');
            $table->string('slug', 160)->unique();
            $table->json('title');
            $table->json('excerpt')->nullable();
            $table->json('body')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->string('author_name', 120)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->index(['type', 'published_at']);
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('location', 150)->nullable();
            $table->unsignedTinyInteger('rating')->default(5);
            $table->json('body');
            $table->string('image_path', 255)->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->json('question');
            $table->json('answer');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 40)->unique();
            $table->string('customer_name', 200);
            $table->string('email', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->char('country', 2)->default('IN');
            $table->char('currency_code', 3)->default('INR'); // display currency chosen
            $table->decimal('fx_rate', 18, 8)->default(1);     // base -> display at order time
            $table->decimal('subtotal', 15, 2)->default(0);    // all amounts in BASE currency
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('shipping', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->enum('status', ['pending', 'paid', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name', 200);
            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0); // base currency
            $table->decimal('line_total', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('cms_pages');
    }
};
