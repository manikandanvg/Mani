<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->string('country', 2)->default('IN');
            $table->string('phone', 20)->nullable();
            $table->string('incharge', 200)->nullable();
            $table->string('gst_no', 25)->nullable();
            $table->decimal('order_limit', 15, 2)->nullable();
            // store-locator geo (front-end map). Wide precision for accurate pins.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // per-branch reseller margins carried from the legacy ci_users fields
            $table->decimal('bill_margin', 8, 3)->default(0);
            $table->decimal('gm_margin', 8, 3)->default(0);
            $table->decimal('stock_trans_margin', 8, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
