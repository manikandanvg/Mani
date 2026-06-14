<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Buyer's GSTIN, captured on the redeem screen and printed in the invoice "Invoice To" block.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('redemption_invoices', function (Blueprint $table) {
            $table->string('buyer_gst', 25)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('redemption_invoices', function (Blueprint $table) {
            $table->dropColumn('buyer_gst');
        });
    }
};
