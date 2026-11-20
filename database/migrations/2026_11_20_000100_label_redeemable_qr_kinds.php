<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-20: the "already redeemed?" check at QR verification must tell a bill's BILLING QR
 * from a Gold Saving RENEWAL QR, but `redeemable_qrs.kind` only exists since 2026-11-15 — every
 * QR minted before that carries NULL.
 *
 * Labels the unlabelled QRs this system minted: a QR saved together with a Gold Saving collection
 * of its bond (within two minutes) is that collection's renewal QR; every other one is the
 * bill's billing QR. Settlement QRs were labelled by 2026_11_15_000100. Legacy (staged) QRs are
 * left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $collections = DB::table('rd_entries')->get(['bond_id', 'created_at'])->groupBy('bond_id');
        $renewal = [];
        $billing = [];
        foreach (DB::table('redeemable_qrs')->where('source', 'system')->whereNull('kind')->get(['id', 'bond_id', 'created_at']) as $q) {
            $at = strtotime((string) $q->created_at);
            $near = ($collections->get($q->bond_id) ?? collect())
                ->contains(fn ($e) => abs(strtotime((string) $e->created_at) - $at) <= 120);
            $near ? $renewal[] = $q->id : $billing[] = $q->id;
        }
        foreach (array_chunk($renewal, 500) as $ids) {
            DB::table('redeemable_qrs')->whereIn('id', $ids)->update(['kind' => 'renewal']);
        }
        foreach (array_chunk($billing, 500) as $ids) {
            DB::table('redeemable_qrs')->whereIn('id', $ids)->update(['kind' => 'billing']);
        }
    }

    public function down(): void
    {
        // labels only — nothing to undo
    }
};
