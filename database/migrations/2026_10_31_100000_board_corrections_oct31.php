<?php

use App\Models\CatalogProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board corrections 2026-10-31:
 *  - Sales invoices are never edited; "delete" is a CANCEL that restores stock and reverses
 *    every activity the bill created (status / cancelled_* columns).
 *  - A stock QR of a cancelled bill is itself cancelled (redeemable_qrs.status + 'cancelled').
 *  - Launch without L-BOX: every branch carries its own STATIC payment QR (UPI id + optional
 *    uploaded QR image) that the app shows for scan-and-pay.
 *  - Catalog items imported from legacy had no unit weight, so the app price list priced
 *    everything at ₹0 and stock pieces could not be derived — derive it from the name
 *    ("8 g Gold", "500 mg Silver") where it is still empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('remarks')->index();
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_by');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE redeemable_qrs MODIFY status ENUM('pending', 'redeemed', 'cancelled') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('redeemable_qrs', fn (Blueprint $table) => $table->string('status', 20)->default('pending')->change());
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->string('upi_id', 80)->nullable()->after('phone');
            $table->string('payment_qr_path', 255)->nullable()->after('upi_id');
        });

        // Unit weight from the catalog name where legacy left it empty.
        foreach (CatalogProduct::withTrashed()->get() as $p) {
            if ((float) $p->default_weight > 0 || $p->material === 'cash') {
                continue;
            }
            $w = CatalogProduct::weightFromName(\App\Support\Translatable::pick($p->name) ?? '');
            if ($w > 0) {
                DB::table('catalog_products')->where('id', $p->id)->update(['default_weight' => $w]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['status', 'cancelled_at', 'cancel_reason']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE redeemable_qrs MODIFY status ENUM('pending', 'redeemed') NOT NULL DEFAULT 'pending'");
        }
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['upi_id', 'payment_qr_path']));
    }
};
