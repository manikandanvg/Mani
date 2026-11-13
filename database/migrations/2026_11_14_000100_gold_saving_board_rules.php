<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lord's board 2026-11-13 — Gold Saving (G11 PLUS 1 / 2 / 3) rules, in force from 1 Nov 2026.
 *
 *  - plans.rd_step      joining amount must be a whole multiple of this (PLUS 2 = ₹1,500,
 *                       PLUS 3 = ₹500 from its ₹1,000 minimum). PLUS 1 needs none: its step is
 *                       the day's 100 mg price.
 *  - bonds.rd_pieces    100 mg pieces fixed at joining (PLUS 1: bill ÷ 100 mg price; PLUS 2:
 *                       bill ÷ ₹1,500). NULL on older bonds = bond value ÷ ₹1,500 (legacy basis).
 *  - rd_settlements     the Gold Saving settlement Head Office makes on the Settlements page:
 *                       dues paid, coins back, deduction, the gold / silver QR given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->decimal('rd_step', 12, 2)->nullable()->after('rd_qr_on');
        });
        Schema::table('bonds', function (Blueprint $t) {
            $t->unsignedInteger('rd_pieces')->nullable()->after('value');
        });
        Schema::create('rd_settlements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_contract_id')->nullable()->index();
            $t->unsignedBigInteger('bond_id')->index();
            $t->unsignedBigInteger('member_id')->index();
            $t->unsignedBigInteger('branch_id')->nullable();          // branch that settled and took the coins back
            $t->unsignedInteger('dues_paid');
            $t->unsignedInteger('dues_total');
            $t->decimal('first_due', 14, 2);
            $t->decimal('paid_total', 14, 2);
            $t->decimal('bonus', 14, 2)->default(0);
            $t->unsignedInteger('coins_given')->default(0);           // 100 mg pieces handed out over the scheme
            $t->unsignedInteger('coins_cancelled')->default(0);       // never redeemed — QR cancelled, counted as returned
            $t->unsignedInteger('coins_returned')->default(0);        // handed back at the counter → branch stock
            $t->unsignedInteger('coins_missing')->default(0);
            $t->decimal('coin_price', 12, 2)->default(0);             // settlement day's 100 mg price
            $t->decimal('deduction', 14, 2)->default(0);              // missing coins × coin price
            $t->decimal('worth', 14, 2);                              // paid + bonus − deduction
            $t->string('metal', 10);                                  // gold | silver
            $t->unsignedBigInteger('redeemable_qr_id')->nullable();
            $t->date('settled_on');
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        DB::table('plans')->where('code', 'P208')->whereNull('rd_step')->update(['rd_step' => 1500, 'min_value' => 1500]);
        DB::table('plans')->where('code', 'P200')->whereNull('rd_step')->update(['rd_step' => 500, 'min_value' => 1000]);
        // PLUS 1: one 100 mg piece at the day's price is the floor — not a fixed rupee minimum
        DB::table('plans')->where('code', 'P209')->update(['min_value' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('rd_settlements');
        Schema::table('bonds', fn (Blueprint $t) => $t->dropColumn('rd_pieces'));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('rd_step'));
        DB::table('plans')->where('code', 'P209')->update(['min_value' => 1500]);
    }
};
