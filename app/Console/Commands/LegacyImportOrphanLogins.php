<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * User 2026-11-02: legacy dealer logins (ci_users) whose branch row was deleted in legacy —
 * or never had one — are the "online" area dealers. `legacy:rollout` skips them because a
 * dealer login needs a branch. This gives each one a placeholder branch (level from the
 * legacy designation, kind dealer, city "Online", no stock, no margins) and the ORIGINAL
 * login: same e-mail, same bcrypt password, role distributor, active flag as in legacy.
 *
 *   php artisan legacy:import-orphan-logins --dry-run
 *   php artisan legacy:import-orphan-logins
 *
 * Idempotent: a login already present (by legacy member code or e-mail) is left alone.
 * Runs against the `legacy` connection (LEGACY_DB_* in .env).
 */
class LegacyImportOrphanLogins extends Command
{
    protected $signature = 'legacy:import-orphan-logins {--dry-run : list only, write nothing}';

    protected $description = 'Create a placeholder branch + the original login for legacy dealer accounts that have no branch';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $legacy = DB::connection('legacy');

        $levelOf = fn (?string $d) => match (Str::lower(trim((string) $d))) {
            'reseller' => 'reseller', 'taluk', 'taluka' => 'taluk', 'zonal' => 'zonal', 'district' => 'district',
            'regional' => 'regional', 'wholesaler' => 'wholesaler', 'area dealer', 'area distributor' => 'area_dealer',
            'sub dealer' => 'sub_dealer', default => 'area_dealer',
        };

        $rows = $legacy->table('ci_users as u')
            ->leftJoin('tbl_branches as b', 'b.brid', '=', 'u.brid')
            ->whereNull('b.brid')
            ->where('u.role', '!=', 1)->where('u.is_admin', '!=', 1)
            ->orderBy('u.id')
            ->get(['u.*']);

        $byCode = User::withTrashed()->whereNotNull('member_code')->get()->keyBy('member_code');
        $taken = User::withTrashed()->pluck('id', 'email')->all();
        [$made, $skipped] = [0, 0];

        foreach ($rows as $u) {
            $code = Str::limit(trim((string) $u->mapid), 30, '') ?: null;
            $email = Str::lower(trim((string) $u->email));
            if (($code && $byCode->has($code)) || ($email !== '' && isset($taken[$email]))) {
                $this->line(sprintf('  = #%-4d %-28s already present', $u->id, $u->username));
                $skipped++;
                continue;
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = Str::slug($u->username ?: ('dealer' . $u->id), '.') . '@dealer.lordicl.local';
                if (isset($taken[$email])) {
                    $email = Str::slug($u->username, '.') . '.' . $u->id . '@dealer.lordicl.local';
                }
            }
            $name = Str::limit(trim($u->firstname . ' ' . $u->lastname) ?: $u->username, 200, '');
            $active = (int) $u->is_active === 1;
            $this->line(sprintf('  + #%-4d %-28s %-12s %-22s login %-30s %s', $u->id, $name, $u->desiname, $code ?? '—', $email, $active ? 'active' : 'INACTIVE'));
            if ($dry) {
                $made++;
                continue;
            }

            DB::transaction(function () use ($u, $code, $email, $name, $active, $levelOf, &$taken, &$byCode) {
                $branch = Branch::create([
                    'name' => $name,
                    'level' => $levelOf($u->desiname),
                    'kind' => 'dealer',
                    'city' => 'Online',
                    'country' => 'IN',
                    'phone' => Str::limit(trim((string) $u->mobile_no), 20, '') ?: null,
                    'incharge' => $name,
                    'is_active' => $active,
                ]);
                $user = new User([
                    'name' => $name,
                    'email' => $email,
                    'phone' => Str::limit(trim((string) $u->mobile_no), 20, '') ?: null,
                    'status' => $active ? 'active' : 'inactive',
                    'branch_id' => $branch->id,
                    'designation' => Str::limit(trim((string) $u->desiname), 60, '') ?: null,
                    'member_code' => $code,
                    'invested' => min((float) $u->invested, 999999999999.99),
                    'locale' => 'en', 'currency_code' => 'INR',
                ]);
                $user->forceFill(['password' => $u->password]);   // legacy bcrypt hash kept as-is (hashed cast)
                $user->save();
                $user->syncRoles(['distributor']);
                $taken[$email] = $user->id;
                if ($code) {
                    $byCode->put($code, $user);
                }
            });
            $made++;
        }

        $this->info(($dry ? '[DRY RUN] would create ' : 'Created ') . $made . ' login(s) with a placeholder branch; ' . $skipped . ' already present.');

        return self::SUCCESS;
    }
}
