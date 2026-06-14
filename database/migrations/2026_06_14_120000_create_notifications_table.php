<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Laravel's standard notifications table — the spine of the app "Inbox & Comms"
 * (Phase 4). Polymorphic `notifiable` so a Member OR a Customer can receive
 * notifications via the database channel; FCM push (Phase 4b) becomes an extra
 * channel writing the same record. `read_at` drives the unread badge.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');           // notifiable_type + notifiable_id
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
