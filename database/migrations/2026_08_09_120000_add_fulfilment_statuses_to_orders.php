<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Online-order fulfilment pipeline (board spec 2026-08-09): placed(pending) →
// confirmed → packed → shipped → delivered / cancelled. Also fixes a latent bug:
// CartController already writes status 'confirmed' on payment verification, but the
// original enum never contained it (non-strict MySQL silently stored '').
return new class extends Migration
{
    private const NEW = "ENUM('pending','confirmed','packed','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending'";

    private const OLD = "ENUM('pending','paid','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending'";

    public function up(): void
    {
        // Repair rows the old enum truncated to '' before tightening anything.
        DB::table('orders')->where('status', '')->update(['status' => 'pending']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE orders MODIFY status ' . self::NEW);
        } else {
            // sqlite (tests): MODIFY/ENUM is MySQL-only — rebuild the column as a
            // plain string, which also drops the old CHECK so 'confirmed'/'packed' fit.
            Schema::table('orders', fn (Blueprint $t) => $t->string('status', 20)->default('pending')->change());
        }
    }

    public function down(): void
    {
        DB::table('orders')->whereIn('status', ['confirmed', 'packed'])->update(['status' => 'paid']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE orders MODIFY status ' . self::OLD);
        }
    }
};
