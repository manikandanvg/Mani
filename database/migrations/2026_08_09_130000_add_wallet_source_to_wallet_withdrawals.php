<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Withdraw My Wallet" (board spec 2026-08-09): a dealer can request a withdrawal
// from EITHER their member commission wallet OR the branch Digi cash wallet
// (branches.digi_cash_balance). `wallet` records which pot the money left, so
// approval/cancel refunds the right one. member_id becomes nullable because a
// branch-Digi withdrawal is a branch transaction, not a member one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_withdrawals', function (Blueprint $table) {
            $table->string('wallet', 12)->default('member_cash')->after('device_id'); // member_cash|branch_digi
            $table->foreignId('member_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_withdrawals', function (Blueprint $table) {
            $table->dropColumn('wallet');
        });
    }
};
