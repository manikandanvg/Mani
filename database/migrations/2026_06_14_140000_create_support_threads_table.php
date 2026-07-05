<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Support chat (Phase 4c) — app user ⇄ HQ. Kept separate from the admin-only
 * conversations/messages tables (those are bound to `users`); a support thread is
 * owned polymorphically by the app identity (Member or Customer). Two read-cursors
 * track unread on each side. `last_message_at` orders the list.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_threads', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');                            // Member | Customer
            $table->string('subject')->nullable();
            $table->string('status', 16)->default('open');      // open | closed
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('owner_last_read_at')->nullable(); // app user's read cursor
            $table->timestamp('support_last_read_at')->nullable(); // HQ's read cursor
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('support_threads')->cascadeOnDelete();
            $table->string('sender', 16);                       // user | support
            $table->foreignId('support_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('source', 16)->default('app');       // app | whatsapp
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_threads');
    }
};
