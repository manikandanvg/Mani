<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zoom auto-create (board phase-1, 2026-08-21): a Zoom meeting is saved with a
 * blank join URL and the link arrives from the Zoom API a moment later — so the
 * column must accept null for that window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('join_url', 800)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('join_url', 800)->nullable(false)->change();
        });
    }
};
