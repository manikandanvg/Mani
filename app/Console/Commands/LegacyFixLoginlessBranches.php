<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * User 2026-11-03: 94 active branches on live show no "View as Dealer" because no active
 * distributor login sits on them. Two causes, one command:
 *
 *  1. SECOND LOGINS (34 branches, real dealerships with members / bonds / bills): the same
 *     person holds TWO legacy logins under one member code at two brids (e.g. a Reseller login
 *     and a later AREA DEALER login). `legacy:rollout` keeps ONE users row per member code,
 *     so the branch of the other ci_users row lost its login. Each such ci_users row gets its
 *     own users row on its own branch — same name, its own bcrypt hash, its own e-mail
 *     (or <slug>.<id>@dealer.lordicl.local), its legacy active flag, role distributor, the same
 *     member code. By-member-code lookups (DealershipService::branchOf …) order by id, so the
 *     login that already existed stays the primary one.
 *
 *  2. EMPTY PLACEHOLDERS (59 branches): legacy tbl_branches rows "LORD JEWELLER / SIVAKASI"
 *     that never had a ci_users login and hold nothing (no members, stock, bonds, invoices,
 *     RD, contracts, margins). They are deactivated so they leave the active list.
 *
 *   php artisan legacy:fix-loginless-branches --dry-run
 *   php artisan legacy:fix-loginless-branches
 *
 * Idempotent; Head Office (branch #1) is never touched. Reads the `legacy` connection.
 */
class LegacyFixLoginlessBranches extends Command
{
    protected $signature = 'legacy:fix-loginless-branches {--dry-run : list only, write nothing}';

    protected $description = 'Create the missing second dealer logins and deactivate empty placeholder branches (branches with no distributor login)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $legacy = DB::connection('legacy');

        $hqId = (int) (Branch::where('legacy_brid', 1)->value('id') ?: 1);
        $byBrid = Branch::withTrashed()->whereNotNull('legacy_brid')->pluck('id', 'legacy_brid')->map(fn ($v) => (int) $v)->all();
        $loginBranches = User::query()->whereNotNull('branch_id')->distinct()->pluck('branch_id')->map(fn ($v) => (int) $v)->flip()->all();
        $taken = User::withTrashed()->pluck('id', 'email')->all();

        // ---- 1. second logins ------------------------------------------------------------
        $this->info('1. Legacy logins whose branch has no login at all');
        $rows = $legacy->table('ci_users')->where('role', '!=', 1)->where('is_admin', '!=', 1)->where('brid', '!=', '')->orderBy('id')->get();
        $made = 0;
        foreach ($rows as $u) {
            $branchId = $byBrid[(int) $u->brid] ?? null;
            if (! $branchId || $branchId === $hqId || isset($loginBranches[$branchId])) {
                continue;
            }
            $code = Str::limit(trim((string) $u->mapid), 30, '') ?: null;
            $name = Str::limit(trim($u->firstname . ' ' . $u->lastname) ?: $u->username, 200, '');
            $email = Str::lower(trim((string) $u->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($taken[$email])) {
                $email = Str::slug($u->username ?: ('dealer' . $u->id), '.') . '.' . $u->id . '@dealer.lordicl.local';
            }
            $active = (int) $u->is_active === 1;
            $other = $code ? User::withTrashed()->where('member_code', $code)->orderBy('id')->first() : null;
            $this->line(sprintf('  + brid %-5d branch #%-5d %-26s %-20s %-36s %-8s %s', $u->brid, $branchId, Str::limit($name, 26, ''), $code ?? '—', $email, $active ? 'active' : 'INACTIVE',
                $other ? 'also login #' . $other->id . ' on branch #' . $other->branch_id : 'first login of this code'));
            $made++;
            $loginBranches[$branchId] = true;
            $taken[$email] = true;
            if ($dry) {
                continue;
            }
            DB::transaction(function () use ($u, $branchId, $code, $name, $email, $active) {
                $user = new User([
                    'name' => $name,
                    'email' => $email,
                    'phone' => Str::limit(trim((string) $u->mobile_no), 20, '') ?: null,
                    'status' => $active ? 'active' : 'inactive',
                    'branch_id' => $branchId,
                    'designation' => Str::limit(trim((string) $u->desiname), 60, '') ?: null,
                    'member_code' => $code,
                    'invested' => min((float) $u->invested, 999999999999.99),
                    'locale' => 'en', 'currency_code' => 'INR',
                ]);
                $user->forceFill(['password' => $u->password]);   // legacy bcrypt hash kept as-is (hashed cast)
                $user->save();
                $user->syncRoles(['distributor']);
            });
        }
        $this->info(($dry ? '   would create ' : '   created ') . $made . ' login(s)');

        // ---- 2. empty placeholders -------------------------------------------------------
        $this->newLine();
        $this->info('2. Active branches with no login, no legacy login and nothing in them');
        $legacyLoginBrids = $rows->pluck('brid')->map(fn ($v) => (int) $v)->flip()->all();
        $candidates = Branch::query()->where('is_active', true)->where('id', '!=', $hqId)
            ->whereNotIn('id', array_keys($loginBranches))->orderBy('id')->get();
        [$off, $kept] = [0, 0];
        foreach ($candidates as $b) {
            if ($b->legacy_brid !== null && isset($legacyLoginBrids[(int) $b->legacy_brid])) {
                continue;   // handled (or still handled) by part 1
            }
            $holds = $this->holdings($b);
            if ($holds) {
                $this->warn(sprintf('  ! branch #%-5d brid %-5s %-26s holds %s — left active, needs a login (board)', $b->id, $b->legacy_brid ?? '—', Str::limit($b->name, 26, ''), $holds));
                $kept++;

                continue;
            }
            $this->line(sprintf('  - branch #%-5d brid %-5s %-26s %-34s empty → inactive', $b->id, $b->legacy_brid ?? '—', Str::limit($b->name, 26, ''), Str::limit((string) $b->incharge, 34, '')));
            $off++;
            if (! $dry) {
                Branch::withoutEvents(fn () => $b->forceFill(['is_active' => false])->save());
            }
        }
        $this->info(($dry ? '   would deactivate ' : '   deactivated ') . $off . ' empty branch(es); ' . $kept . ' with data left for the board');

        if (! $dry) {
            \App\Services\Activity\ActivityLogger::log('master', 'loginless_branches_fixed',
                sprintf('%d second dealer login(s) created, %d empty placeholder branch(es) deactivated', $made, $off),
                ['details' => ['logins' => $made, 'deactivated' => $off, 'kept_with_data' => $kept]]);
        }

        return self::SUCCESS;
    }

    /** What a branch holds, as a short list — empty string when nothing. */
    protected function holdings(Branch $b): string
    {
        $parts = [];
        $count = fn (string $table) => (int) DB::table($table)->where('branch_id', $b->id)->count();
        foreach (['members', 'bonds', 'sales_invoices', 'rd_entries', 'member_contracts', 'reseller_commissions'] as $t) {
            if (($n = $count($t)) > 0) {
                $parts[] = $n . ' ' . str_replace('_', ' ', $t);
            }
        }
        $stock = (int) DB::table('stock')->where('branch_id', $b->id)->where('quantity', '<>', 0)->count();
        if ($stock > 0) {
            $parts[] = $stock . ' stock';
        }
        $money = (float) $b->bill_margin + (float) $b->digi_cash_balance + (float) $b->gold_gm_margin + (float) $b->silver_gm_margin + (float) $b->stock_trans_margin;
        if (abs($money) > 0.005) {
            $parts[] = 'margins ₹' . number_format($money, 2);
        }

        return implode(', ', $parts);
    }
}
