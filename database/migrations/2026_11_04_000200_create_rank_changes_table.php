<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-04: register every TBP-stage (rank) shift of a distributor with the date, so
 * Head Office can report who moved up or down and when. One row per change, written by
 * NetworkService on both paths (billing-time evaluateRank and the bulk ranks:recompute).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('from_rank_id')->nullable()->constrained('ranks')->nullOnDelete();
            $table->foreignId('to_rank_id')->constrained('ranks')->cascadeOnDelete();
            $table->unsignedTinyInteger('from_depth')->default(0);
            $table->unsignedTinyInteger('to_depth')->default(0);
            $table->string('direction', 8);                 // up | down
            $table->decimal('unpure_bv', 15, 2)->default(0);  // the member's ranking BV at the change
            $table->decimal('unpure_gbv', 15, 2)->default(0);
            $table->string('source', 16);                   // billing | recompute | cancel
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['changed_at']);
            $table->index(['member_id', 'changed_at']);
            $table->index(['direction', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_changes');
    }
};
