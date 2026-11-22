<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-22: a deleted member's data is NOT kept in the live tables. It is moved to its
 * own store — one row per deleted member + one row per live record that belonged to them —
 * so the deleted-member list can be handed to Lord's admin when asked for.
 * Deliberately no foreign key to any live table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();          // the id it had in `members`
            $table->string('member_code', 40)->index();
            $table->string('name', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('upline_code', 40)->nullable();
            $table->string('referrer_code', 40)->nullable();
            $table->date('joined_on')->nullable();
            $table->string('mode', 20)->default('force');              // force | with_invoice
            $table->unsignedInteger('bills_count')->default(0);
            $table->decimal('issued_total', 15, 2)->default(0);        // paid out / redeemed
            $table->decimal('recovered_total', 15, 2)->default(0);     // taken back from wallets
            $table->decimal('charged_total', 15, 2)->default(0);       // charged to branch cash stock
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('deleted_by_name', 120)->nullable();
            $table->longText('profile');                               // the whole members row (JSON)
            $table->longText('statement')->nullable();                 // the settled statement (JSON)
            $table->unsignedInteger('records_count')->default(0);
            $table->timestamp('removed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('deleted_member_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deleted_member_id')->constrained('deleted_members')->cascadeOnDelete();
            $table->string('source_table', 64);
            $table->string('source_id', 64)->nullable();
            $table->boolean('purged')->default(false);                 // true = the row was removed from the live table
            $table->longText('data');                                  // the whole row (JSON)
            $table->timestamp('created_at')->nullable();
            $table->index(['deleted_member_id', 'source_table']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_member_records');
        Schema::dropIfExists('deleted_members');
    }
};
