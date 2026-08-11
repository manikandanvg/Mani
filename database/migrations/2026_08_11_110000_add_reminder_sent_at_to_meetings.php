<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Meeting "starting soon" reminders (board 2026-08-11): stamp when the reminder
// broadcast went out so the scheduler can never send it twice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
