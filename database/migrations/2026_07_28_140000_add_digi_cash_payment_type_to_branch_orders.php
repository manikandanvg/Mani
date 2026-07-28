<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock orders can be paid from the branch Digi cash wallet (stock-return vouchers). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->enum('payment_type', ['cash', 'cheque', 'online', 'digi_cash', 'others'])
                ->default('cash')->change();
        });
    }

    public function down(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->enum('payment_type', ['cash', 'cheque', 'online', 'others'])
                ->default('cash')->change();
        });
    }
};
