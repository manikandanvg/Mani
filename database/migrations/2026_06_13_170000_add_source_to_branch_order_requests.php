<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch order requests can now originate from the redemption flow (a branch that redeemed
 * stock needs HQ to replenish it). `source` distinguishes those from normal Order-Form
 * requests; `source_ref` links a redemption-sourced request back to its redemption invoice
 * (and guards against raising it twice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->string('source', 20)->default('order_form')->after('request_no');
            $table->unsignedBigInteger('source_ref')->nullable()->after('source');
            $table->index(['source', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $table) {
            $table->dropIndex(['source', 'source_ref']);
            $table->dropColumn(['source', 'source_ref']);
        });
    }
};
