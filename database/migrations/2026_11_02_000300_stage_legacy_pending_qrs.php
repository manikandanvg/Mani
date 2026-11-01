<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-01 — the legacy pending QRs are decided on two Commissions pages instead of
 * being settled by the cut-over script:
 *
 *  - Pending Cash QR: every legacy digi-gold withdrawal request (and the dealer-side cash
 *    tickets that have no request) waits for HQ to choose "cash wallet" or "digi-gold"
 *    (digi_withdrawals: source, qr_code, legacy_ref, rate, decision, decided_by/at).
 *  - Pending Gold QR: every legacy invoice QR becomes a redeemable QR (source legacy) that is
 *    sent over WhatsApp and redeemed at ANY branch at the gold price of its own date
 *    (redeemable_qrs: source, priced_on, frozen_rate; bond_id nullable — 35 QRs belong to
 *    contracts deleted in legacy). digi_queue keeps the legacy per-date rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redeemable_qrs', function (Blueprint $table) {
            $table->unsignedBigInteger('bond_id')->nullable()->change();
            $table->string('source', 16)->default('system')->after('qr_mode')->index();
            $table->date('priced_on')->nullable()->after('source');
            $table->decimal('frozen_rate', 12, 2)->nullable()->after('priced_on');
        });
        Schema::table('digi_queue', function (Blueprint $table) {
            $table->decimal('rate', 12, 2)->nullable()->after('cash_worth');
        });
        Schema::table('digi_withdrawals', function (Blueprint $table) {
            $table->string('source', 16)->default('request')->after('status')->index();
            $table->string('qr_code', 16)->nullable()->after('source');
            $table->string('legacy_ref', 40)->nullable()->after('qr_code');
            $table->decimal('rate', 12, 2)->nullable()->after('legacy_ref');
            $table->string('decision', 16)->nullable()->after('rate');
            $table->unsignedBigInteger('decided_by')->nullable()->after('decision');
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('remarks', 255)->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('digi_withdrawals', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'qr_code', 'legacy_ref', 'rate', 'decision', 'decided_by', 'decided_at', 'remarks']);
        });
        Schema::table('digi_queue', fn (Blueprint $table) => $table->dropColumn('rate'));
        Schema::table('redeemable_qrs', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'priced_on', 'frozen_rate']);
        });
    }
};
