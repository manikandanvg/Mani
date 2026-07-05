<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * App-facing engagement on community posts (Phase 5a). The existing social_likes /
 * social_comments tables are bound to `users` (admin); these are the mobile audience's
 * equivalents, with a polymorphic actor so a Member OR a Customer can react/comment
 * without a User login. Counts shown in the app are derived from these tables.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('social_post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->morphs('reactor');                       // Member | Customer
            $table->string('type', 16)->default('like');
            $table->timestamps();

            $table->unique(['post_id', 'reactor_type', 'reactor_id'], 'reaction_unique');
        });

        Schema::create('social_post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->morphs('author');                        // Member | Customer
            $table->text('body');
            $table->timestamps();

            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_comments');
        Schema::dropIfExists('social_post_reactions');
    }
};
