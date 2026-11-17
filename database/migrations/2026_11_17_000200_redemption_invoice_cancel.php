<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A redemption made by mistake can be cancelled (user 2026-11-17): the invoice stays on
 * record, soft-deleted, with who cancelled it and why. See RedemptionCancelService.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('redemption_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('redemption_invoices', 'deleted_at')) {
                $table->softDeletes();
            }
            if (! Schema::hasColumn('redemption_invoices', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable();
            }
            if (! Schema::hasColumn('redemption_invoices', 'cancel_reason')) {
                $table->string('cancel_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('redemption_invoices', function (Blueprint $table) {
            foreach (['deleted_at', 'cancelled_by', 'cancel_reason'] as $col) {
                if (Schema::hasColumn('redemption_invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
