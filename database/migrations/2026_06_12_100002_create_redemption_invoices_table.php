<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redemption = the ACTUAL sale (the bond/contract was only an acknowledgement). When a
 * customer redeems their stock QR, a proper TAX INVOICE is raised here (legacy
 * tbl_qrsalesinvoice). Payment mode defaults to "pending": for branch redemptions the
 * branch fronts the goods and reclaims from HQ later (a future phase).
 *
 * Also adds OTP fields to redeemable_qrs for the redeem handshake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemption_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 40)->unique();
            $table->date('invoice_date');
            $table->foreignId('redeemable_qr_id')->nullable()->constrained('redeemable_qrs')->nullOnDelete();
            $table->foreignId('bond_id')->nullable()->constrained('bonds')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();  // redeeming branch
            $table->string('reference_no', 60)->nullable();    // member code / origin invoice
            $table->string('referrer_name', 200)->nullable();
            $table->string('payment_mode', 20)->default('pending');
            $table->string('payment_reference', 120)->nullable();
            $table->decimal('gold_rate', 12, 2)->default(0);
            $table->decimal('silver_rate', 12, 2)->default(0);
            $table->decimal('taxable_total', 15, 2)->default(0);   // incl WC+MC, pre-GST
            $table->decimal('cgst', 15, 2)->default(0);
            $table->decimal('sgst', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->boolean('dealer_created')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'invoice_date']);
        });

        Schema::create('redemption_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redemption_invoice_id')->constrained('redemption_invoices')->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->nullable()->constrained('catalog_products')->nullOnDelete();
            $table->string('description', 200)->nullable();    // e.g. "1 G GOLD"
            $table->string('hsn_code', 20)->nullable();
            $table->string('material', 20)->nullable();
            $table->decimal('unit_weight', 12, 4)->default(0); // grams per piece
            $table->decimal('quantity', 12, 3)->default(0);    // number of pieces
            $table->decimal('rate', 15, 2)->default(0);        // ₹ per piece (material value)
            $table->decimal('making', 15, 2)->default(0);
            $table->decimal('wastage', 15, 2)->default(0);
            $table->decimal('gst_pct', 6, 3)->default(0);
            $table->decimal('amount', 15, 2)->default(0);      // qty × rate (pre-charges)
            $table->decimal('line_total', 15, 2)->default(0);  // incl charges + gst
        });

        Schema::table('redeemable_qrs', function (Blueprint $table) {
            $table->string('otp', 8)->nullable()->after('qr_sent');
            $table->timestamp('otp_sent_at')->nullable()->after('otp');
        });
    }

    public function down(): void
    {
        Schema::table('redeemable_qrs', function (Blueprint $table) {
            $table->dropColumn(['otp', 'otp_sent_at']);
        });
        Schema::dropIfExists('redemption_lines');
        Schema::dropIfExists('redemption_invoices');
    }
};
