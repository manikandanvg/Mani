<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Live & Learn (Phase 6a) — scheduled Zoom (or other) meetings the app deep-links into.
 * HQ schedules them; the app lists upcoming/live/past and opens `join_url` externally.
 * Visibility mirrors the community: 'members' (closed) or 'public'.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('platform', 20)->default('zoom');     // zoom | meet | other
            $table->string('join_url', 800);
            $table->string('meeting_id', 60)->nullable();
            $table->string('passcode', 60)->nullable();
            $table->string('host_name')->nullable();
            $table->timestamp('scheduled_at');
            $table->unsignedInteger('duration_min')->default(60);
            $table->string('visibility', 16)->default('members'); // members | public
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['is_published', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
