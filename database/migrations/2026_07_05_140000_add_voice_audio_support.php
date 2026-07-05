<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Multilanguage voice for L-BOX: announcements get a server-rendered WAV
 * (free/self-hosted TTS — Piper for English, eSpeak-NG for Tamil; see
 * config/lbox.php) and each device gets a spoken language. The box streams
 * the audio_url; if rendering is unavailable it falls back to beeps + OLED.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('voice_announcements', function (Blueprint $table) {
            $table->string('audio_path')->nullable()->after('payload');   // public disk WAV
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->string('language', 5)->default('en')->after('board_type');   // en | ta
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('language'));
        Schema::table('voice_announcements', fn (Blueprint $table) => $table->dropColumn('audio_path'));
    }
};
