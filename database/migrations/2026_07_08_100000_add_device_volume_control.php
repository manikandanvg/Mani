<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HQ speaker-volume control for L-BOX devices: the desired level (1-5) rides
 * back on every heartbeat; the box applies it once per change (volume_updated_at
 * doubles as the change-version the firmware compares against).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('volume_level')->nullable()->after('language');
            $table->timestamp('volume_updated_at')->nullable()->after('volume_level');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['volume_level', 'volume_updated_at']);
        });
    }
};
