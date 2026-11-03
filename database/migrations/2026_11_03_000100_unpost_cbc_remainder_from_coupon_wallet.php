<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-03: the coupon wallet (member_wallets.coupon_balance) is a separate, admin-only
 * wallet kept for another purpose — CBC never posts to it. The CBC remainder (coupon worth minus
 * cash share minus TDS/service) stays recorded on each coupon row as coupon_amount, retained by
 * Head Office. Take back what the approvals of 2 Nov already posted there (88 coupons on live).
 */
return new class extends Migration
{
    public function up(): void
    {
        $posted = DB::table('cbc_entries')->where('status', 'paid')->where('coupon_amount', '>', 0)
            ->select('member_id', DB::raw('SUM(coupon_amount) AS amt'))->groupBy('member_id')->get();
        $max = DB::getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';
        foreach ($posted as $p) {
            DB::table('member_wallets')->where('member_id', $p->member_id)
                ->update(['coupon_balance' => DB::raw($max . '(0, coupon_balance - ' . (float) $p->amt . ')')]);
        }
    }

    public function down(): void
    {
        // the amounts remain on cbc_entries.coupon_amount; nothing to restore
    }
};
