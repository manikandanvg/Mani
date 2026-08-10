<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement('ALTER TABLE orders MODIFY status ' . self::NEW);
    }

    public function down(): void
    {
        DB::table('orders')->whereIn('status', ['confirmed', 'packed'])->update(['status' => 'paid']);
        DB::statement('ALTER TABLE orders MODIFY status ' . self::OLD);
    }
};
