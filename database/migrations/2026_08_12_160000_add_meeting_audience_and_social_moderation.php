<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-08-12 draft:
 *  - item 7: meeting audience targeting — optional minimum TBP rank depth
 *    (1=Taluk … 5=Corporate) on top of the everyone/distributors visibility.
 *  - item 8: closed-social moderation — admin can hide member posts/comments
 *    without deleting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_rank_depth')->nullable()->after('visibility');
        });
        Schema::table('social_posts', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('visibility');
        });
        Schema::table('social_post_comments', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', fn (Blueprint $t) => $t->dropColumn('min_rank_depth'));
        Schema::table('social_posts', fn (Blueprint $t) => $t->dropColumn('is_hidden'));
        Schema::table('social_post_comments', fn (Blueprint $t) => $t->dropColumn('is_hidden'));
    }
};
