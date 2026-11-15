<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-15 — a memo can be addressed: everyone (as before), dealers only (distributors
 * who hold a branch login), chosen ranks (the same picker as a Zoom meeting), or particular
 * distributors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memos', function (Blueprint $t) {
            $t->string('audience', 12)->default('all')->after('body');   // all | dealers | ranks | members
            $t->json('audience_ranks')->nullable()->after('audience');    // rank depths
            $t->json('audience_members')->nullable()->after('audience_ranks');   // member ids
        });
    }

    public function down(): void
    {
        Schema::table('memos', fn (Blueprint $t) => $t->dropColumn(['audience', 'audience_ranks', 'audience_members']));
    }
};
