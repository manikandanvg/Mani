<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-07-28 board spec, part 2:
 *
 * 1) RD gold-QR config on plans — G11 PLUS1/PLUS2 QRs are backed by a fixed gold
 *    weight (100 mg) priced at the live rate + making% + wastage% + GST:
 *      rd_qr_grams      gold grams backing each RD QR / fixed renewal amount (0.100)
 *      rd_qr_on         when RD QRs mint: 'always' (bill + every renewal, PLUS1) |
 *                       'first_renewal' (one-time, PLUS2) | null (never, PLUS3)
 *      rd_qr_product_id catalog product supplying making/wastage/GST percentages
 *
 * 2) branches.digi_cash_balance — the branch Digi cash wallet: credited by approved
 *    stock returns, spendable on stock orders (payment_type digi_cash).
 *
 * 3) stock_returns / stock_return_lines — branch-in-charge returns stock to Head
 *    Office; on approval the stock moves branch → HQ and the voucher amount lands
 *    in the branch Digi cash wallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('rd_qr_grams', 8, 3)->nullable()->after('settlement_suspend');
            $table->string('rd_qr_on', 20)->nullable()->after('rd_qr_grams');
            $table->foreignId('rd_qr_product_id')->nullable()->after('rd_qr_on')
                ->constrained('catalog_products')->nullOnDelete();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('digi_cash_balance', 15, 2)->default(0)->after('bill_margin');
        });

        Schema::create('stock_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_return_id')->constrained('stock_returns')->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->constrained('catalog_products');
            $table->string('material', 20)->nullable();
            $table->decimal('weight', 12, 3)->default(0);
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        // G11 PLUS1: QR at bill + every renewal (11 total); PLUS2: one QR at first renewal.
        DB::table('plans')->where('code', 'P209')->update(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'always']);
        DB::table('plans')->where('code', 'P208')->update(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'first_renewal']);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_return_lines');
        Schema::dropIfExists('stock_returns');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('digi_cash_balance');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rd_qr_product_id');
            $table->dropColumn(['rd_qr_grams', 'rd_qr_on']);
        });
    }
};
