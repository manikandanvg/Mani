<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Push registration (Phase 4b). One row per device per signed-in identity, so a
 * notification fans out to every device the owner is logged in on. Polymorphic
 * `owner` (Member or Customer) mirrors the inbox. `token` is the FCM/Expo push
 * token (unique — re-registering the same device just refreshes the owner/seen).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');                         // owner_type + owner_id
            $table->string('token', 512)->unique();
            $table->string('platform', 16)->default('android'); // android | ios | web
            $table->string('provider', 16)->default('fcm');     // fcm | expo
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            // morphs('owner') already indexes (owner_type, owner_id).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
