<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vendors / suppliers we PURCHASE goods from (Master 1.2). GST captured for
// input-credit; referenced later by the Trade > Purchase module.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('contact_person', 200)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('gst_no', 25)->nullable();
            $table->string('pan', 15)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->char('country', 2)->default('IN');
            $table->string('bank_name', 150)->nullable();
            $table->string('bank_acno', 30)->nullable();
            $table->string('ifsc', 15)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
