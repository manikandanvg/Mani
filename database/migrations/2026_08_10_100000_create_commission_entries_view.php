<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// One read-only VIEW over all three commission source tables (board 2026-08-10):
// the "Commission Ledgers" screen must show EVERY stream's distribution status —
// IC/GAP live in commission_ledger, CBC in cbc_entries, and the 5 margins in
// reseller_commissions. Crediting still happens only via Commission Approval.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS commission_entries');
        DB::statement(<<<'SQL'
CREATE VIEW commission_entries AS
SELECT
    CONCAT('L', id)             AS uid,
    'ledger'                    AS source,
    id                          AS source_id,
    type                        AS type,
    member_id,
    from_member_id,
    bond_id,
    invoice_no,
    level,
    amount,
    tds,
    service_charge,
    net_amount,
    status,
    earned_on,
    paid_on,
    branch_id,
    currency_code,
    created_at
FROM commission_ledger
UNION ALL
SELECT
    CONCAT('C', id),
    'cbc',
    id,
    'CBC',
    member_id,
    NULL,
    bond_id,
    NULL,
    NULL,
    worth,
    0,
    0,
    NULL,
    status,
    cbc_date,
    paid_on,
    NULL,
    currency_code,
    created_at
FROM cbc_entries
UNION ALL
SELECT
    CONCAT('R', id),
    'reseller',
    id,
    CASE com_type_id
        WHEN 1 THEN 'BILL_MARGIN'
        WHEN 2 THEN 'GOLD_MARGIN'
        WHEN 3 THEN 'SILVER_MARGIN'
        WHEN 4 THEN 'STOCK_TRANSFER_MARGIN'
        WHEN 5 THEN 'RD_RENEWAL_MARGIN'
        ELSE 'RESELLER'
    END,
    reference_member_id,
    NULL,
    NULL,
    invoice_no,
    NULL,
    com_value,
    tds,
    service_charge,
    net_amount,
    status,
    bill_date,
    paid_on,
    branch_id,
    currency_code,
    created_at
FROM reseller_commissions
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS commission_entries');
    }
};
