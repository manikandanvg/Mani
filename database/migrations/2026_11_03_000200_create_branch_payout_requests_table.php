<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Board 2026-11-03 — a branch never fronts the cash for a wallet withdrawal.
 *
 *   pending  → the member scanned the branch QR (wallet already debited)
 *   requested → the branch bundled pending rows into ONE payout request to Head Office
 *   admin_paid → HQ transferred the bundle to the branch (bank, outside the system)
 *   disbursed → the branch handed the cash/gold to the member
 *
 * branch_payout_requests = the bundle HQ pays; wallet_withdrawals.payout_request_id
 * links each row to it. Only HQ can cancel a row (refund to the wallet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_payout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('rows_count')->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status', 12)->default('requested');   // requested | paid | cancelled
            $table->string('payment_ref', 80)->nullable();        // UTR / transfer reference typed by HQ
            $table->string('note')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::table('wallet_withdrawals', function (Blueprint $table) {
            $table->foreignId('payout_request_id')->nullable()->after('device_id')
                ->constrained('branch_payout_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_withdrawals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_request_id');
        });
        Schema::dropIfExists('branch_payout_requests');
    }
};
