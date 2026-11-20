<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\ResellerCommission;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * User 2026-11-20: a dealer who held TWO logins in the old system (one member ID at two brids,
 * e.g. LJW24693982077: Wholesaler brid 416 + Area Dealer brid 523) has ONE login here, so the
 * stock, members and bills of the other branch cannot be reached — "restore my stock".
 * Ruling: do NOT make a second login / change the e-mail — MERGE the two accounts into one.
 *
 * For every old-system login whose branch has no login here, and whose member ID AND phone match
 * the login that exists, the two branches become ONE entity. The SUPERIOR position survives
 * (Branch::LEVELS order, e.g. Wholesaler over Area Distributor; same position → the login's
 * branch) and the login moves to it if needed:
 *
 *   stock      every piece and the cash stock move over (two stock movements, ref branch_merge)
 *   records    members, bills, contracts, QRs, Gold Saving entries, invoices, orders,
 *              commissions, devices … now belong to the login's branch
 *   margins    the old balances are NOT carried: each margin balance is reset to what is still
 *              UNPAID on the branch's bills (pending only); branch cash collected by QR adds up
 *   branch     the other branch is deleted (soft), with the person's empty placeholder branches
 *
 * The login itself (e-mail, password, role, level of its branch) is not touched.
 *
 *   php artisan legacy:merge-second-branches            (dry run)
 *   php artisan legacy:merge-second-branches --apply
 *   php artisan legacy:merge-second-branches --only=LJW24693982077 --apply
 */
class LegacyMergeSecondBranches extends Command
{
    protected $signature = 'legacy:merge-second-branches
        {--apply : write the changes (without it: list only)}
        {--only= : one member ID only}
        {--connection=legacy : old-system database connection}';

    protected $description = 'Merge the login-less second branch of a dealer (two old-system logins, one member ID) into the branch of their login — stock, members, bills, margins';

    /** margin balance column => the commission types that feed it */
    protected const MARGINS = [
        'bill_margin' => [ResellerCommission::COM_BILL_MARGIN, ResellerCommission::COM_RD_RENEWAL_MARGIN],
        'gold_gm_margin' => [ResellerCommission::COM_GOLD_MARGIN],
        'silver_gm_margin' => [ResellerCommission::COM_SILVER_MARGIN],
        'stock_trans_margin' => [ResellerCommission::COM_STOCK_TRANSFER_MARGIN],
    ];

    /** branch columns handled by hand, or that must stay as history of the old branch */
    protected const SKIP = [
        'stock.branch_id', 'stock_movements.branch_id', 'activity_logs.branch_id',
        'pending_bill_margins.branch_id', 'branch_attendances.branch_id', 'branch_stock_days.branch_id',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only = trim((string) $this->option('only')) ?: null;
        $legacy = DB::connection((string) $this->option('connection'));

        $hqId = (int) (Branch::where('legacy_brid', 1)->value('id') ?: 1);
        $byBrid = Branch::whereNotNull('legacy_brid')->where('is_active', true)->pluck('id', 'legacy_brid')->map(fn ($v) => (int) $v)->all();
        $loginBranches = User::query()->whereNotNull('branch_id')->distinct()->pluck('branch_id')->map(fn ($v) => (int) $v)->flip()->all();
        $columns = $this->branchColumns();

        $rows = $legacy->table('ci_users')->where('role', '!=', 1)->where('is_admin', '!=', 1)->where('brid', '!=', '')->orderBy('id')->get();
        [$done, $left] = [0, 0];
        $table = [];
        foreach ($rows as $u) {
            $from = $byBrid[(int) $u->brid] ?? null;
            $code = Str::limit(trim((string) $u->mapid), 30, '') ?: null;
            if (! $from || $from === $hqId || isset($loginBranches[$from]) || ! $code || ($only && $only !== $code)) {
                continue;
            }
            $login = User::where('member_code', $code)->whereNotNull('branch_id')->orderBy('id')->first();
            $source = Branch::find($from);
            $phone = $this->digits($u->mobile_no);
            $why = match (true) {
                ! $login => 'no login of this ID here',
                (int) $login->branch_id === $from || (int) $login->branch_id === $hqId => 'login is not on a dealer branch',
                $phone === '' || $phone !== $this->digits($login->phone) => 'phone differs: old ' . ($phone ?: '—') . ', login ' . ($login->phone ?: '—') . ' — check by hand',
                (float) $source->digi_cash_balance < 0 => 'negative branch cash on the branch — board',
                default => null,
            };
            if ($why) {
                $table[] = [$code, '#' . $from . ' (brid ' . $u->brid . ')', $login ? '#' . $login->branch_id : '—', '', '', '', '', '', 'LEFT: ' . $why];
                $left++;

                continue;
            }
            // the SUPERIOR position survives (user 2026-11-20: Wholesaler over Area Distributor);
            // same position → the branch the dealer already signs in to. The login follows.
            $other = (int) $login->branch_id;
            $to = Branch::levelIndex($source->level) < Branch::levelIndex(Branch::find($other)->level) ? $from : $other;
            $from = $to === $from ? $other : $from;
            $moved = null;
            try {
                DB::transaction(function () use ($from, $to, $columns, $apply, &$moved, $code, $phone) {
                    $moved = $this->merge($from, $to, $columns);
                    $moved['empties'] = $this->deleteEmptyTwins($to, $code, $phone);
                    if (! $apply) {
                        throw new \RuntimeException('dry-run');
                    }
                });
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'dry-run') {
                    throw $e;
                }
            }
            $kept = Branch::find($to);
            $table[] = [$code, '#' . $from . ' ' . Branch::levelLabel(Branch::withTrashed()->find($from)->level) . ($moved['empties'] ? ' + ' . $moved['empties'] . ' empty' : ''),
                '#' . $to . ' ' . Branch::levelLabel($kept->level) . ' · ' . $login->email,
                $moved['pieces'] + 0, number_format($moved['cash'], 2), $moved['records'], number_format($moved['old_balance'], 2), number_format($moved['pending'], 2), ($apply ? 'MERGED' : 'would merge') . ($to !== $other ? ', login moved' : '')];
            $done++;
            if ($apply) {
                \App\Services\Activity\ActivityLogger::log('master', 'branch_merged',
                    sprintf('Branch #%d merged into #%d (%s) — one dealer, two old-system logins', $from, $to, $code),
                    ['details' => ['from' => $from, 'to' => $to, 'member_code' => $code] + $moved]);
            }
        }

        $this->table(['Member ID', 'Branch deleted', 'Branch kept (superior position)', 'Pieces', 'Cash stock', 'Records', 'Old margin (dropped)', 'Margin pending', 'Result'], $table);
        $this->info(($apply ? 'APPLY: ' : 'DRY RUN: ') . $done . ' branch(es) ' . ($apply ? 'merged' : 'would be merged') . ' into the dealer\'s superior branch; ' . $left . ' left.');
        if (! $apply) {
            $this->line('Nothing was written. Add --apply to do it.');
        }

        return self::SUCCESS;
    }

    /** Fold branch $from into branch $to. Returns what moved. */
    protected function merge(int $from, int $to, array $columns): array
    {
        $out = ['pieces' => 0.0, 'cash' => 0.0, 'records' => 0, 'old_balance' => 0.0, 'pending' => 0.0];
        $source = Branch::lockForUpdate()->findOrFail($from);
        $target = Branch::lockForUpdate()->findOrFail($to);
        $today = Carbon::now()->toDateString();
        $now = Carbon::now();

        // --- stock: every line added to the same line of the login's branch ---
        foreach (DB::table('stock')->where('branch_id', $from)->lockForUpdate()->get() as $row) {
            $qty = (float) $row->quantity;
            if (abs($qty) < 0.00005) {
                continue;
            }
            $mine = DB::table('stock')->where('branch_id', $to)->where('catalog_product_id', $row->catalog_product_id)
                ->where('order_line_id', $row->order_line_id)->lockForUpdate()->first();
            if ($mine) {
                $balance = round((float) $mine->quantity + $qty, 4);
                DB::table('stock')->where('id', $mine->id)->update(['quantity' => $balance, 'updated_at' => $now]);
                DB::table('stock')->where('id', $row->id)->update(['quantity' => 0, 'updated_at' => $now]);
            } else {
                $balance = $qty;
                DB::table('stock')->where('id', $row->id)->update(['branch_id' => $to, 'updated_at' => $now]);   // the whole line moves
            }
            foreach ([[$from, -$qty, 0, $to], [$to, $qty, $balance, $from]] as [$branch, $change, $after, $other]) {
                DB::table('stock_movements')->insert([
                    'branch_id' => $branch, 'catalog_product_id' => $row->catalog_product_id, 'type' => 'transfer',
                    'qty_change' => $change, 'balance_after' => $after, 'ref_type' => 'branch_merge', 'ref_id' => $other,
                    'note' => 'Branch #' . $from . ' merged into #' . $to . ' (same dealer, two old-system logins)',
                    'moved_on' => $today, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $isCash = DB::table('catalog_products')->where('id', $row->catalog_product_id)->value('name');
            Str::contains((string) $isCash, 'CASH') ? $out['cash'] += $qty : $out['pieces'] += $qty;
        }

        // --- every record of the branch now belongs to the login's branch ---
        foreach ($columns as [$table, $column]) {
            $out['records'] += DB::table($table)->where($column, $from)->update([$column => $to]);
        }

        // --- pending bill margin rows: one per (branch, source) ---
        foreach (DB::table('pending_bill_margins')->where('branch_id', $from)->get() as $row) {
            $mine = DB::table('pending_bill_margins')->where('branch_id', $to)->where('source', $row->source)->first();
            if ($mine && $mine->status === $row->status) {
                DB::table('pending_bill_margins')->where('id', $mine->id)->update(['amount' => round((float) $mine->amount + (float) $row->amount, 2)]);
                DB::table('pending_bill_margins')->where('id', $row->id)->delete();
            } elseif (! $mine) {
                DB::table('pending_bill_margins')->where('id', $row->id)->update(['branch_id' => $to]);
            }
        }

        // --- margins: NO old balance is carried (user 2026-11-20: "reset their margin, pending only") ---
        // each margin balance of the login's branch = what is still unpaid on its bills (old-system
        // rows up to 22-10-2026 were paid there); the branch cash collected by QR is real and adds up
        $set = [];
        foreach (self::MARGINS as $column => $types) {
            $set[$column] = round((float) ResellerCommission::where('branch_id', $to)->whereIn('com_type_id', $types)
                ->where('status', '!=', 'paid')->payable()->sum('com_value'), 2);
            $out['old_balance'] += (float) $source->{$column} + (float) $target->{$column};
            $out['pending'] += $set[$column];
        }
        $set['digi_cash_balance'] = round((float) $target->digi_cash_balance + (float) $source->digi_cash_balance, 2);
        DB::table('branches')->where('id', $to)->update($set + ['updated_at' => $now]);
        // the other entity is deleted (soft: its number and history stay on record)
        DB::table('branches')->where('id', $from)->update(array_fill_keys(array_keys($set), 0) + ['is_active' => false, 'deleted_at' => $now, 'updated_at' => $now]);
        $out['old_balance'] = round($out['old_balance'], 2);
        $out['pending'] = round($out['pending'], 2);

        return $out;
    }

    /**
     * One dealer = ONE entity: the same person's other placeholder branches (same member ID in
     * the in-charge name AND the same phone), with no login and nothing in them, are deleted too.
     */
    protected function deleteEmptyTwins(int $keep, string $code, string $phone): int
    {
        $n = 0;
        foreach (Branch::where('id', '!=', $keep)->where('incharge', 'like', '%/ ' . $code)->get() as $b) {
            if ($this->digits($b->phone) !== $phone || User::where('branch_id', $b->id)->exists() || $this->holdsAnything($b)) {
                continue;
            }
            DB::table('branches')->where('id', $b->id)->update(['is_active' => false, 'deleted_at' => Carbon::now()]);
            $n++;
        }

        return $n;
    }

    protected function holdsAnything(Branch $b): bool
    {
        foreach (['members', 'bonds', 'sales_invoices', 'rd_entries', 'member_contracts', 'reseller_commissions', 'redemption_invoices', 'branch_order_requests'] as $t) {
            if (DB::table($t)->where('branch_id', $b->id)->exists()) {
                return true;
            }
        }

        return DB::table('stock')->where('branch_id', $b->id)->where('quantity', '<>', 0)->exists()
            || abs((float) $b->bill_margin + (float) $b->digi_cash_balance + (float) $b->gold_gm_margin + (float) $b->silver_gm_margin + (float) $b->stock_trans_margin) > 0.005;
    }

    /** last 10 digits of a phone number */
    protected function digits(?string $phone): string
    {
        return substr(preg_replace('/[^0-9]/', '', (string) $phone), -10);
    }

    /** Every table.column that points at a branch (foreign keys + the known plain ones). */
    protected function branchColumns(): array
    {
        $found = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $t) {
            foreach (Schema::getForeignKeys($t) as $fk) {
                if ($fk['foreign_table'] === 'branches' && count($fk['columns']) === 1) {
                    $found[$t . '.' . $fk['columns'][0]] = [$t, $fk['columns'][0]];
                }
            }
        }
        foreach ([['member_contracts_removed', 'branch_id'], ['rd_settlements', 'branch_id'], ['redeemable_qrs', 'only_branch_id']] as [$t, $c]) {
            if (Schema::hasColumn($t, $c)) {
                $found[$t . '.' . $c] = [$t, $c];
            }
        }

        return array_values(array_diff_key($found, array_flip(self::SKIP)));
    }
}
