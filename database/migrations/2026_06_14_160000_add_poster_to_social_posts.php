<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Member-authored team feed (Phase 5b). social_posts can now be authored by an app
 * identity (Member/Customer) via a polymorphic `poster`, not just an HQ `users` row.
 * HQ announcements keep author_id; member posts leave it null and set poster_*.
 */
return new class extends Migration {
    public function up(): void
    {
        // author_id becomes optional (member posts have no HQ user author).
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
        });
        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->change();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->nullableMorphs('poster');           // Member | Customer (app author)
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropMorphs('poster');
        });
    }
};
