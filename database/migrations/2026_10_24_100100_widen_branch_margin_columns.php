<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy dealers carry accrued margins of several lakh (ci_users.billmargin up to ₹6.7 L,
 * stocktransmargin ₹3 L); decimal(8,3) tops out at ₹99,999.999 and the roll-out import
 * overflowed. Widen to decimal(15,3) like the other money columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $t) {
            $t->decimal('bill_margin', 15, 3)->default(0)->change();
            $t->decimal('stock_trans_margin', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $t) {
            $t->decimal('bill_margin', 8, 3)->default(0)->change();
            $t->decimal('stock_trans_margin', 8, 3)->default(0)->change();
        });
    }
};
