<?php

namespace App\Services;

use App\Models\DeletedMember;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-22: "don't keep live table data — create separate deleted member's data".
 *
 * archive() takes a full snapshot of everything a member owns into deleted_members /
 * deleted_member_records, then REMOVES the member's own rows from the live tables: profile,
 * wallet, contracts (bonds), coupons, own incentive lines, stock QRs, Gold Saving entries,
 * withdrawals, Digi holdings, app devices / tokens / notifications.
 *
 * What stays live, on purpose — it is the company's or somebody else's record, and it loses
 * only its link to the member (a copy is still in the snapshot):
 *   sales invoices          a bill is never deleted, it stays CANCELLED (board 2026-10-31)
 *   redemption invoices     goods really left the branch; they are in the GST registers
 *   other people's lines    the incentive an upline was paid on this member's bills,
 *                           the dealer's margins, stock movements, order requests
 *   the panel login      closed (inactive), not removed — records it made still point at it
 *   the activity log
 */
class DeletedMemberArchiver
{
    /** Rows of the member: [table, column, purge?] — purge false = snapshot only, the row stays live. */
    protected const BY_MEMBER = [
        ['member_wallets', 'member_id', true],
        ['bonds', 'member_id', true],
        ['member_contracts', 'member_id', true],
        ['cbc_entries', 'member_id', true],
        ['commission_ledger', 'member_id', true],
        ['commission_ledger', 'from_member_id', false],
        ['commission_entries', 'member_id', true],
        ['commission_entries', 'from_member_id', false],
        ['contract_settlements', 'member_id', true],
        ['rd_settlements', 'member_id', true],
        ['rd_entries', 'member_id', true],
        ['rd_mandates', 'member_id', true],
        ['redeemable_qrs', 'member_id', true],
        ['wallet_withdrawals', 'member_id', true],
        ['digi_withdrawals', 'member_id', true],
        ['digi_gold_purchases', 'member_id', true],
        ['digi_gold_txns', 'member_id', true],
        ['scan_payments', 'member_id', true],
        ['epin_logs', 'member_id', true],
        ['epins', 'owner_member_id', true],
        ['payout_statements', 'member_id', true],
        ['member_month_snapshots', 'member_id', true],
        ['rank_changes', 'member_id', true],
        ['mobile_devices', 'member_id', true],
        ['phone_verifications', 'member_id', true],
        ['sales_invoices', 'customer_member_id', false],
        ['redemption_invoices', 'member_id', false],
        ['branch_order_requests', 'member_id', false],
        ['sales_returns', 'member_id', false],
        ['support_tickets', 'member_id', false],
        ['digi_orders', 'member_id', false],
        ['digi_queue', 'member_id', false],
        ['orders', 'member_id', false],
    ];

    /** Rows hanging on the member's contracts: [table, column, purge?]. */
    protected const BY_BOND = [
        ['cbc_entries', 'bond_id', true],
        ['rd_entries', 'bond_id', true],
        ['rd_mandates', 'bond_id', true],
        ['rd_settlements', 'bond_id', true],
        ['contract_settlements', 'bond_id', true],
        ['redeemable_qrs', 'bond_id', true],
        ['member_contracts', 'bond_id', true],
        ['commission_ledger', 'bond_id', false],   // what the people above earned — theirs
        ['redemption_invoices', 'bond_id', false],
    ];

    /**
     * Snapshot and purge in one go (a member with nothing to settle).
     *
     * @param array{mode?:string, reason?:?string, by?:?int, statement?:?array} $meta
     */
    public function archive(Member $member, array $meta = []): DeletedMember
    {
        return DB::transaction(fn () => $this->purge($this->snapshot($member, $meta), $member));
    }

    /**
     * Step 1 — BEFORE anything of the member is reversed: copy the profile and every row that
     * belongs to the member, exactly as they stand, into the deleted-member store.
     *
     * @param array{mode?:string, reason?:?string, by?:?int, statement?:?array} $meta
     */
    public function snapshot(Member $member, array $meta = []): DeletedMember
    {
        // deployed without `php artisan migrate`: refuse cleanly, nothing is touched
        abort_unless(Schema::hasTable('deleted_members') && Schema::hasTable('deleted_member_records'), 422,
            'The deleted-members store is not set up on this server yet (run the database migration) — the member was NOT deleted.');

        return DB::transaction(function () use ($member, $meta) {
            $by =! empty($meta['by']) ? User::find($meta['by']) : null;
            $statement = $meta['statement'] ?? null;
            unset($statement['member']);
            $profile = $this->raw('members', 'id', [$member->id])->first();

            $archive = DeletedMember::create([
                'member_id' => $member->id,
                'member_code' => $member->member_code,
                'name' => $member->name,
                'phone' => $member->phone,
                'upline_code' => $member->upline_id ? Member::withTrashed()->whereKey($member->upline_id)->value('member_code') : null,
                'referrer_code' => $member->referrer_id ? Member::withTrashed()->whereKey($member->referrer_id)->value('member_code') : null,
                'joined_on' => $member->joined_on,
                'mode' => $meta['mode'] ?? 'force',
                'bills_count' => is_array($statement) ? count($statement['bills'] ?? []) : 0,
                'issued_total' => (float) ($statement['totals']['issued'] ?? 0),
                'recovered_total' => (float) ($statement['totals']['recover'] ?? 0),
                'charged_total' => (float) ($statement['totals']['charge'] ?? 0),
                'reason' => isset($meta['reason']) ? mb_substr((string) $meta['reason'], 0, 255) : null,
                'deleted_by' => $by?->id,
                'deleted_by_name' => $by?->name,
                'profile' => (array) $profile,
                'statement' => $statement,
                'removed_at' => Carbon::now(),
            ]);

            $bondIds = DB::table('bonds')->where('member_id', $member->id)->pluck('id')->all();
            $invoices = DB::table('sales_invoices')->where('customer_member_id', $member->id)->get(['id', 'invoice_no']);
            $logins = User::where('member_code', $member->member_code)->whereNull('branch_id')->pluck('id')->all();
            $morph = [$member->getMorphClass(), Member::class];

            // ── 1. snapshot ──
            $seen = [];
            $count = 0;
            $keep = function (string $table, string $column, array $values, bool $purged) use ($archive, &$seen, &$count) {
                if ($values === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    return;
                }
                foreach ($this->raw($table, $column, $values)->cursor() as $row) {
                    $row = (array) $row;
                    $id = isset($row['id']) ? (string) $row['id'] : (isset($row['member_id']) ? 'member:' . $row['member_id'] : null);
                    $key = $table . '#' . ($id ?? md5(json_encode($row)));
                    if (isset($seen[$key])) {
                        if ($purged && ! $seen[$key]['purged']) {
                            DB::table('deleted_member_records')->where('id', $seen[$key]['id'])->update(['purged' => true]);
                            $seen[$key]['purged'] = true;
                        }

                        continue;
                    }
                    $recordId = DB::table('deleted_member_records')->insertGetId([
                        'deleted_member_id' => $archive->id, 'source_table' => $table, 'source_id' => $id,
                        'purged' => $purged, 'data' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'created_at' => Carbon::now(),
                    ]);
                    $seen[$key] = ['id' => $recordId, 'purged' => $purged];
                    $count++;
                }
            };
            foreach (self::BY_MEMBER as [$table, $column, $purge]) {
                $keep($table, $column, [$member->id], $purge);
            }
            foreach (self::BY_BOND as [$table, $column, $purge]) {
                $keep($table, $column, $bondIds, $purge);
            }
            $keep('sale_lines', 'invoice_id', $invoices->pluck('id')->all(), false);
            $keep('reseller_commissions', 'invoice_no', $invoices->pluck('invoice_no')->filter()->all(), false);
            $keep('users', 'id', $logins, false);
            if (Schema::hasTable('device_tokens')) {
                foreach (DB::table('device_tokens')->whereIn('owner_type', $morph)->where('owner_id', $member->id)->get() as $row) {
                    DB::table('deleted_member_records')->insert(['deleted_member_id' => $archive->id, 'source_table' => 'device_tokens',
                        'source_id' => (string) $row->id, 'purged' => true, 'data' => json_encode((array) $row), 'created_at' => Carbon::now()]);
                    $count++;
                }
            }
            $archive->update(['records_count' => $count]);

            return $archive;
        });
    }

    /**
     * Step 2 — AFTER every money matter is settled: remove the member's own rows from the live
     * tables (children first). The snapshot is all that is left of them.
     */
    public function purge(DeletedMember $archive, Member $member): DeletedMember
    {
        return DB::transaction(function () use ($archive, $member) {
            $bondIds = DB::table('bonds')->where('member_id', $member->id)->pluck('id')->all();
            $morph = [$member->getMorphClass(), Member::class];

            DB::table('personal_access_tokens')->whereIn('tokenable_type', $morph)->where('tokenable_id', $member->id)->delete();
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->whereIn('notifiable_type', $morph)->where('notifiable_id', $member->id)->delete();
            }
            if (Schema::hasTable('device_tokens')) {
                DB::table('device_tokens')->whereIn('owner_type', $morph)->where('owner_id', $member->id)->delete();
            }
            foreach (self::BY_BOND as [$table, $column, $purge]) {
                if ($purge && $bondIds && Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::table($table)->whereIn($column, $bondIds)->delete();
                }
            }
            foreach (array_reverse(self::BY_MEMBER) as [$table, $column, $purge]) {
                if ($purge && ! in_array($table, ['bonds', 'member_wallets'], true) && Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::table($table)->where($column, $member->id)->delete();
                }
            }
            DB::table('bonds')->where('member_id', $member->id)->delete();
            DB::table('member_wallets')->where('member_id', $member->id)->delete();
            // a panel login of that code is CLOSED, not removed: bills, orders and approvals it
            // made still point at it (a dealer login is refused before we get here)
            User::where('member_code', $member->member_code)->update(['status' => 'inactive']);
            DB::table('members')->where('id', $member->id)->delete();

            return $archive;
        });
    }

    protected function raw(string $table, string $column, array $values)
    {
        return DB::table($table)->whereIn($column, $values);
    }
}
