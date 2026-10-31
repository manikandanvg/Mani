<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-10-31: every activity on the platform is logged — data changes, admin actions,
 * app requests, logins, console jobs and EVERY communication (push, inbox, WhatsApp, OTP) —
 * and Head Office gets a filterable, exportable viewer (System → Activity Log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('category', 30)->index();          // see ActivityLog::CATEGORIES
            $table->string('action', 60)->index();            // created / updated / push_sent / otp_sent / login …
            $table->string('status', 12)->default('ok')->index();   // ok | failed | denied
            $table->string('channel', 20)->nullable()->index();     // push | inbox | whatsapp | sms | email | api | panel | console
            $table->string('summary', 255);
            // who did it
            $table->string('actor_type', 20)->nullable()->index();  // admin | member | device | system | guest
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 120)->nullable();
            // what it was about
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 120)->nullable();
            $table->unsignedBigInteger('member_id')->nullable()->index();   // affected distributor
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->json('details')->nullable();             // changes, payload, gateway answer …
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 40)->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
