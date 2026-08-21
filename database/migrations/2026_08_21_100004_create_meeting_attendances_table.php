<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live-meeting attendance (board phase-1, 2026-08-21). Two row sources:
 *   app  — the member tapped Join (identity is certain; one row per member)
 *   zoom — Zoom's participant_joined/left webhooks (duration is authoritative;
 *          member_id filled when the participant could be matched)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('participant_name', 200)->nullable();
            $table->string('zoom_participant_id', 60)->nullable();
            $table->string('source', 10)->default('app');   // app | zoom
            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();
            $table->unsignedInteger('duration_min')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'member_id']);
            $table->index(['meeting_id', 'zoom_participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendances');
    }
};
