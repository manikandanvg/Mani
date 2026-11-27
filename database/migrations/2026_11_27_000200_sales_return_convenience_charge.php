<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-27: a sales return of a gold coin carries a convenience charge (the cost of
 * recycling the coin) of ₹50 a gram on top of the coin value; silver carries none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_returns', 'convenience_charge')) {
                $table->decimal('convenience_charge', 14, 2)->default(0)->after('credit_value');
            }
            if (! Schema::hasColumn('sales_returns', 'total_value')) {
                $table->decimal('total_value', 14, 2)->nullable()->after('convenience_charge');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            foreach (['convenience_charge', 'total_value'] as $col) {
                if (Schema::hasColumn('sales_returns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
