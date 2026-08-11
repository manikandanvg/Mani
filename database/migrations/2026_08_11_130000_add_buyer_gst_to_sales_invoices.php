<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Board 2026-08-11: B2B sales need the buyer's GSTIN on the tax invoice (GSTR-1
// B2B section). Nullable — blank means an unregistered consumer (B2CS).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('buyer_gst', 25)->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('buyer_gst');
        });
    }
};
