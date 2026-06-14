<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Self-contained social component: posts, media, likes, comments, follows.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->enum('visibility', ['public', 'members', 'private'])->default('public');
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['visibility', 'created_at']);
        });

        Schema::create('social_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('type', 30)->default('image');
            $table->unsignedInteger('sort')->default(0);
        });

        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('social_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('social_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['follower_id', 'following_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_follows');
        Schema::dropIfExists('social_likes');
        Schema::dropIfExists('social_comments');
        Schema::dropIfExists('social_post_media');
        Schema::dropIfExists('social_posts');
    }
};
