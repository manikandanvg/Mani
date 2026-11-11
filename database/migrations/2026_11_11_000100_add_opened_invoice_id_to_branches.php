<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complaint 2026-11-11 (1): billing the Area Distributor scheme opens the dealer's branch +
 * login on the spot (legacy parity). The branch remembers the bill that opened it, so
 * cancelling that bill can close the branch and its login again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('opened_invoice_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('opened_invoice_id');
        });
    }
};
