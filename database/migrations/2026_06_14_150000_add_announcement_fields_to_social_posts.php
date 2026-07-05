<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Community (Phase 5a): social_posts doubles as the HQ announcement board for the app.
 * `title` gives announcements a headline, `pinned` floats them, `published_at` allows
 * scheduling (NULL = visible immediately; a future time hides it until then).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('title')->nullable()->after('author_id');
            $table->boolean('pinned')->default(false)->after('visibility');
            $table->timestamp('published_at')->nullable()->after('pinned');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['title', 'pinned', 'published_at']);
        });
    }
};
