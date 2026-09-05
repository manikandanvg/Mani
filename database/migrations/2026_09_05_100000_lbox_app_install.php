<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L-BOX app-assisted install (board 2026-08-23 items 1–5, built 2026-09-05).
 *
 * The phone is the BLE bridge for a box that has no network yet: the app creates
 * (or claims) the device row for the installer's branch, hands the box its Wi-Fi +
 * pairing code over BLE, and anchors it with the phone's GPS. Boxes already online
 * over 4G get Wi-Fi pushed in the heartbeat instead (wifi_* columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('mac', 17)->nullable()->after('serial_no');
            // Pushed to the box on its next heartbeat when wifi_updated_at moves.
            $table->string('wifi_ssid', 64)->nullable()->after('notes');
            $table->text('wifi_pass')->nullable()->after('wifi_ssid');   // encrypted cast
            $table->timestamp('wifi_updated_at')->nullable()->after('wifi_pass');
            $table->foreignId('installed_by_member_id')->nullable()->after('wifi_updated_at')
                ->constrained('members')->nullOnDelete();
            $table->timestamp('installed_at')->nullable()->after('installed_by_member_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installed_by_member_id');
            $table->dropColumn(['mac', 'wifi_ssid', 'wifi_pass', 'wifi_updated_at', 'installed_at']);
        });
    }
};
