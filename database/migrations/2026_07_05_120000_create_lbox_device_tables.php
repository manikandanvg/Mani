<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L-BOX smart branch device (internal-only, LORD Jeweller exclusive). The box does
 * RFID attendance (writes the same attendance_records payroll reads, source='device'),
 * announces payments by voice, and takes OTA firmware updates. Cloud side lives HERE
 * as a module — no separate lbox-cloud platform (decision 2026-07-05).
 */
return new class extends Migration {
    public function up(): void
    {
        // One row per physical box. HQ creates it with a pairing code; the device
        // redeems the code once at first boot for its Sanctum bearer token.
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 80);
            $table->string('serial_no', 40)->unique();
            $table->string('board_type', 8)->default('lite');      // lite | pro
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pairing_code', 16)->nullable();        // one-time; cleared on register
            $table->string('status', 16)->default('provisioned');  // provisioned | active | suspended | retired
            $table->string('firmware_version', 24)->nullable();
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->smallInteger('rssi')->nullable();              // dBm (negative)
            $table->unsignedBigInteger('uptime_s')->nullable();
            $table->string('ip', 45)->nullable();
            $table->decimal('lat', 10, 7)->nullable();             // Pro GPS fix
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Voice queue: the box polls, speaks, then ACKs. Payments land here from the
        // Razorpay webhook; HQ can queue test/custom announcements from Filament.
        Schema::create('voice_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24)->default('custom');         // payment | attendance | custom | test
            $table->string('message');                             // text the box speaks (TTS) / shows
            $table->json('payload')->nullable();                   // amount etc. for display
            $table->string('status', 12)->default('pending');      // pending | delivered | acked
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
        });

        // OTA firmware repository (binary on the 'local' private disk).
        Schema::create('firmwares', function (Blueprint $table) {
            $table->id();
            $table->string('board_type', 8);                       // lite | pro
            $table->string('version', 24);
            $table->string('path');                                // storage path of the .bin
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(false);          // one active per board_type
            $table->timestamps();

            $table->unique(['board_type', 'version']);
        });

        // RFID card mapping for attendance taps at the box.
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->string('rfid_tag', 32)->nullable()->unique()->after('esic_number');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropUnique(['rfid_tag']);
            $table->dropColumn('rfid_tag');
        });
        Schema::dropIfExists('firmwares');
        Schema::dropIfExists('voice_announcements');
        Schema::dropIfExists('devices');
    }
};
