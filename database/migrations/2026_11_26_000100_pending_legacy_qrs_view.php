<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Board 2026-11-26: Commissions → Pending Gold QR lists BOTH legacy routes in one table.
 *
 *   INVOICE — the legacy invoice QR (redeemable_qrs, source legacy): sent on WhatsApp and
 *             redeemed at a branch. No conversion.
 *   DIGIWD  — the legacy digi-gold withdrawal QR (digi_withdrawals): Head Office sends the
 *             grams to the distributor's digi-gold (gold) wallet as a purchase; the QR then
 *             reads "redeemed".
 *
 * A read-only view, so the page keeps one sortable / searchable / totalled table. Nothing
 * is stored here — the two source tables stay the only record.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $uid = fn (string $prefix) => $sqlite ? "'{$prefix}-' || id" : "CONCAT('{$prefix}-', id)";

        DB::statement('DROP VIEW IF EXISTS pending_legacy_qrs');
        DB::statement("
            CREATE VIEW pending_legacy_qrs AS
            SELECT {$uid('I')} AS uid, 'INVOICE' AS route, id AS source_id, member_id, bond_id, branch_id, invoice_no,
                   qr_code, qr_mode, priced_on, frozen_rate, gram_worth, cash_worth, status, qr_sent, sent_at,
                   redeem_branch_id, redeemed_at, NULL AS decision, NULL AS decided_by, created_at
              FROM redeemable_qrs
             WHERE source = 'legacy'
            UNION ALL
            SELECT {$uid('W')} AS uid, 'DIGIWD' AS route, id AS source_id, member_id, NULL AS bond_id, NULL AS branch_id, NULL AS invoice_no,
                   qr_code, 'cash' AS qr_mode, DATE(created_at) AS priced_on, rate AS frozen_rate, gold_wt AS gram_worth, worth AS cash_worth,
                   CASE WHEN status = 'pending' THEN 'pending' ELSE 'redeemed' END AS status, 0 AS qr_sent, NULL AS sent_at,
                   NULL AS redeem_branch_id, decided_at AS redeemed_at, decision, decided_by, created_at
              FROM digi_withdrawals
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS pending_legacy_qrs');
    }
};
