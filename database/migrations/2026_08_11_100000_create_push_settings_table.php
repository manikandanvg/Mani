<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Board decision 2026-08-11: WhatsApp is OTP-only; every acknowledgement (QR,
// contract, invoice, news, reminders, settlement, payments, L-BOX scans …) goes
// out as an app push notification + inbox entry. This singleton row holds the
// gateway credentials — FCM (Android/iOS via Firebase) and APNs (native Apple,
// creds to be added later) — editable on System → Push Notification Settings,
// with .env config as the fallback, mirroring the WhatsApp settings pattern.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_settings', function (Blueprint $table) {
            $table->id();
            // Firebase Cloud Messaging (HTTP v1 service account)
            $table->boolean('fcm_enabled')->default(true);
            $table->string('fcm_project_id', 120)->nullable();
            $table->string('fcm_client_email', 190)->nullable();
            $table->text('fcm_private_key')->nullable();
            // Apple Push Notification service (for a future native-APNs path)
            $table->boolean('apns_enabled')->default(false);
            $table->string('apns_key_id', 40)->nullable();
            $table->string('apns_team_id', 40)->nullable();
            $table->string('apns_bundle_id', 190)->nullable();
            $table->text('apns_private_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_settings');
    }
};
