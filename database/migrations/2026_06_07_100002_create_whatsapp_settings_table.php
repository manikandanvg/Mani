<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row WhatsApp gateway settings (mirrors legacy tbl_whatsappapi) so the
 * instance id / access token can be rotated from the admin (System → WhatsApp
 * Settings) without an .env deploy — they change about weekly.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('url')->nullable();
            $table->string('instance_id')->nullable();
            $table->string('access_token')->nullable();
            $table->string('country_code', 5)->default('91');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings');
    }
};
