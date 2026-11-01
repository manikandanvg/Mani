<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** digi_queue.delivery_status gains "staged" (legacy invoice QR moved to redeemable_qrs). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE digi_queue MODIFY delivery_status ENUM('pending','redeemed','staged') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('digi_queue', fn (Blueprint $t) => $t->string('delivery_status', 16)->default('pending')->change());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('digi_queue')->where('delivery_status', 'staged')->update(['delivery_status' => 'pending']);
            DB::statement("ALTER TABLE digi_queue MODIFY delivery_status ENUM('pending','redeemed') NOT NULL DEFAULT 'pending'");
        }
    }
};
