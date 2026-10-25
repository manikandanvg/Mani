<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ROLL-OUT migration of the legacy CodeIgniter `lordicl` database (connection "legacy")
 * into the new schema — board resolution 2026-10-24.
 *
 * Differs from the evaluation importer (legacy:import) in exactly the ways the board asked:
 *
 *  - Head office is SIVAKASI (legacy brid 1 = "LORD JEWELLER", 62K Gandhi Road); the seeded
 *    Coimbatore placeholder is overwritten, never duplicated.
 *  - Schemes are matched to the board-approved v2 plan sheet BY NAME (the sheet reshuffled
 *    the codes: legacy 208 Zonal is P207 here, 211 Area Distributor is P210 …). Existing
 *    plan configuration is never touched; a legacy scheme with no v2 counterpart is kept as
 *    an INACTIVE "L###" plan so its bonds still resolve.
 *  - Dealer logins (ci_users) become branch-scoped `distributor` users with their bcrypt
 *    password hashes intact, their branch gets its dealership level / margins / source, and
 *    every branch starts as kind = dealer (HQ = branch); the board converts dealers into
 *    Lord's Branches on the Branch form afterwards.
 *  - Members get their branch (the login that entered them) and their TBP rank.
 *  - HUGE history is DISCLOSED AS ONE LINE per distributor instead of row-by-row:
 *      · Sales Incentive (IC, 5.2 lakh rows)   → one ledger line per member per status
 *      · Turnover salary (GAP payout log)      → one line per member per status (TDS/SC kept)
 *      · Dealer billing margin (53k rows)      → one line per dealer branch
 *      · Digi gold (1.3 lakh buys / 47k draws) → one OPENING balance line per member
 *    Each line carries the row count it stands for ("LEGACY-IC ×1,234").
 *  - CBC coupons, bonds, RD instalments, sales invoices and their lines stay row-level: CBC
 *    coupons are individually redeemable and contracts/invoices are legal documents, so they
 *    cannot be shrunk.
 *  - Pending digi withdrawals / digi QR queue rows stay as rows (they are actionable).
 *
 *   php artisan legacy:rollout --fresh --seed-plans
 */
class LegacyRolloutImport extends LegacyImport
{
    protected $signature = 'legacy:rollout
        {--fresh : Wipe transactional target tables before importing}
        {--seed-plans : Seed the board-approved v2 scheme sheet first (fresh database)}
        {--dry-run : Report what would be imported without writing}';

    protected $description = 'ROLL-OUT import of the legacy lordicl database (Sivakasi HQ, v2 schemes, dealer logins, single-line history)';

    /** legacy ci_users.id => new users.id */
    protected array $userMap = [];

    /** legacy ci_users.id => legacy brid (string) */
    protected array $userBranch = [];

    /** legacy brid => new branch id (parent's $branchMap is keyed the same way) */
    protected array $warnings = [];

    public function handle(): int
    {
        $this->legacy = DB::connection('legacy');
        DB::connection()->disableQueryLog();
        $start = microtime(true);

        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        $existing = DB::table('bonds')->count();
        if ($existing > 0 && ! $this->option('fresh')) {
            $this->error("Target bonds table already has {$existing} rows. Re-run with --fresh to wipe transactional tables first.");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->wipeTransactional();
        }

        $this->importLiveRate();
        $this->importMous();
        if ($this->option('seed-plans')) {
            $this->info('Seeding the v2 scheme sheet (PlanSchemaV2Seeder)…');
            (new \Database\Seeders\PlanSchemaV2Seeder)->run();
        }
        $this->importPlans();
        $this->importBranches();
        $this->importCategories();
        $this->importCatalogProducts();
        $this->importStock();
        $this->importMembers();
        $this->linkMemberNetwork();
        $this->importWallets();
        $this->importDealerLogins();
        $this->importCustomers();
        $this->importBonds();
        $this->importRdEntries();
        $this->importCbcEntries();
        $this->importIcCommissions();
        $this->importGapCommissions();
        $this->importResellerCommissions();
        $this->importSalesInvoices();
        $this->importSaleLines();
        $this->importDigiOpening();
        $this->importDigiQueue();
        $this->importDigiWithdrawals();
        $this->importEpinLogs();

        $this->table(['step', 'imported', 'skipped'], collect($this->stats)
            ->map(fn ($s, $k) => [$k, $s['in'], $s['skip']])->values()->all());
        foreach ($this->warnings as $w) {
            $this->warn($w);
        }
        $this->info(sprintf('Legacy ROLL-OUT import complete in %.1fs', microtime(true) - $start));

        return self::SUCCESS;
    }

    protected function wipeTransactional(): void
    {
        parent::wipeTransactional();
        Schema::disableForeignKeyConstraints();
        foreach (['payout_statements', 'member_wallets'] as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->truncate();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    // ------------------------------------------------------------------ plans

    /** Legacy planid => v2 plan id, matched by normalised name; unmatched => inactive L### plan. */
    protected function importPlans(): void
    {
        $byName = Plan::all()->keyBy(fn (Plan $p) => $this->planKey((string) ($p->name['en'] ?? $p->name)));
        [$in, $skip] = [0, 0];

        foreach ($this->legacy->table('tbl_planjewel')->orderBy('planid')->get() as $p) {
            $key = $this->planKey($p->planname);
            $plan = $byName->get($key);
            if (! $plan) {
                $type = (int) $p->plantypeid;
                $plan = Plan::firstOrCreate(
                    ['code' => 'L' . $p->planid],
                    [
                        'name' => ['en' => $p->planname . ' (legacy)'],
                        'plan_type' => $type ?: 1,
                        'type' => match ($type) { 1 => 'rd', 2 => 'digital', 3 => 'gold', 4 => 'silver', default => 'others' },
                        'min_value' => $this->num($p->minvalue),
                        'allocation_bv' => $this->num($p->allocation),
                        'validity_months' => (int) ($p->levelcomduration ?: 12),
                        'cbc_value' => $this->num($p->cbvalue),
                        'cbc_count' => (int) $p->cbcount,
                        'level_depth' => (int) $p->leveldepth,
                        'ic_schedule' => $this->numbers($p->iccom),
                        'level_schedule' => $this->numbers($p->levelcom),
                        'level_com_duration' => (int) $p->levelcomduration,
                        'billing_margin' => $this->num($p->billingmargin),
                        'epin_count' => (int) $p->epincount,
                        'mou_id' => $this->mouMap[$p->moutype] ?? null,
                        'is_active' => false,   // retired legacy scheme: history only, never billable
                    ],
                );
                $this->warnings[] = "Plan {$p->planid} \"{$p->planname}\" has no v2 counterpart → kept INACTIVE as {$plan->code}";
                $skip++;
            } else {
                $in++;
            }
            $this->planMap[(int) $p->planid] = $plan->id;
            $this->line(sprintf('  plan %s %-32s → %s', $p->planid, $p->planname, $plan->code));
        }
        $this->note('plans (name-matched)', $in, $skip);
    }

    protected function planKey(string $name): string
    {
        $k = Str::lower($name);
        $k = str_replace(['dealership', 'plan', 'contractors', 'contractor'], '', $k);

        return preg_replace('/[^a-z0-9]+/', '', $k);
    }

    // --------------------------------------------------------------- branches

    protected function importBranches(): void
    {
        // Head office = legacy brid 1 (LORD JEWELLER, Sivakasi). Overwrite the seeded
        // placeholder in place so every FK to branch #1 keeps pointing at HQ.
        $hq = Branch::where('level', 'hq')->orderBy('id')->first() ?? Branch::orderBy('id')->first();
        $l = $this->legacy->table('tbl_branches')->where('brid', 1)->first();
        if ($hq && $l) {
            $hq->forceFill([
                'name' => $this->clip(trim($l->storename) ?: 'LORD JEWELLER', 200),
                'address' => $this->clip($this->plain($l->address), 255),
                'city' => $this->clip(Str::title(trim($this->plain($l->city))) ?: 'Sivakasi', 120),
                'pincode' => $this->clip(preg_match('/\d{6}/', (string) $l->pincode, $m) ? $m[0] : null, 12),
                'phone' => $this->clip($l->phoneno, 20),
                'gst_no' => $this->clip($l->gstno, 25),
                'level' => 'hq', 'kind' => Branch::KIND_BRANCH, 'is_active' => true,
                'invoice_prefix' => $hq->invoice_prefix ?: 'HQ',
            ])->save();
        }
        $this->branchMap[1] = $hq?->id;

        $existing = Branch::withTrashed()->get()->keyBy(fn (Branch $b) => $this->branchKey($b->name, $b->city));
        [$in, $merged] = [0, 0];
        foreach ($this->legacy->table('tbl_branches')->orderBy('brid')->get() as $b) {
            if ((int) $b->brid === 1) {
                continue;
            }
            $name = $this->clip(trim($this->plain($b->storename)) ?: ('Branch ' . $b->brid), 200);
            $city = $this->clip(trim($this->plain($b->city)) ?: null, 120);
            $key = $this->branchKey($name, $city);
            if ($existing->has($key)) {
                $this->branchMap[(int) $b->brid] = $existing[$key]->id;
                $merged++;
                continue;
            }
            $branch = Branch::create([
                'name' => $name,
                'kind' => Branch::KIND_DEALER,
                'address' => $this->clip($this->plain($b->address), 255), 'city' => $city,
                'pincode' => $this->clip(preg_match('/\d{6}/', (string) $b->pincode, $m) ? $m[0] : null, 12),
                'country' => 'IN', 'phone' => $this->clip($b->phoneno, 20),
                'incharge' => $this->clip(trim((string) $b->brincharge) === '-' ? null : $b->brincharge, 200),
                'gst_no' => $this->clip($b->gstno, 25), 'is_active' => (int) $b->status === 1,
            ]);
            $existing[$key] = $branch;
            $this->branchMap[(int) $b->brid] = $branch->id;
            $in++;
        }
        $this->note('branches', $in, $merged);
    }

    protected function branchKey(?string $name, ?string $city): string
    {
        return Str::lower(trim((string) $name)) . '|' . Str::lower(trim((string) $city));
    }

    protected function plain(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $t = preg_replace('/<br\s*\/?>/i', ', ', $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');

        return trim(preg_replace('/\s*,\s*,+/', ',', preg_replace('/\s+/', ' ', $t)), " ,\t\n\r");
    }

    // ---------------------------------------------------------------- members

    /** Parent import + branch (entering login's branch) + TBP rank (legacy mylevel 1..6). */
    protected function importMembers(): void
    {
        parent::importMembers();

        // ci_users.id => brid, for members.branch_id via tbl_member.entryby
        foreach ($this->legacy->table('ci_users')->select('id', 'brid')->get() as $u) {
            $this->userBranch[(int) $u->id] = (string) $u->brid;
        }
        $ranks = Rank::orderBy('depth')->pluck('id', 'depth')->all();   // depth 0..5
        [$in, $skip] = [0, 0];

        $this->legacy->table('tbl_member')
            ->select('userid', 'entryby', 'mylevel')
            ->whereNotNull('userid')->where('userid', '!=', '')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use ($ranks, &$in, &$skip) {
                foreach ($rows as $m) {
                    $id = $this->memberMap[$this->clip($m->userid, 30)] ?? null;
                    if (! $id) {
                        $skip++;
                        continue;
                    }
                    $brid = $this->userBranch[(int) $m->entryby] ?? null;
                    $branchId = $brid !== null && $brid !== '' ? ($this->branchMap[(int) $brid] ?? null) : null;
                    $depth = max(0, min(5, ((int) $m->mylevel) - 1));
                    DB::table('members')->where('id', $id)->update([
                        'branch_id' => $branchId,
                        'rank_id' => $ranks[$depth] ?? $ranks[0],
                    ]);
                    $in++;
                }
            });
        $this->note('member branch + rank', $in, $skip);
    }

    // ---------------------------------------------------------- dealer logins

    /**
     * Legacy ci_users (all roles but the super admin) → branch-scoped `distributor` users.
     * Password hashes are bcrypt in both systems, so every dealer keeps their password.
     * The dealer's branch gets its dealership level (desiname), accrued margins, digi cash
     * and its stock source (uplineid → that dealer's branch).
     */
    protected function importDealerLogins(): void
    {
        [$in, $skip] = [0, 0];
        $levelOf = fn (?string $d) => match (Str::lower(trim((string) $d))) {
            'reseller' => 'reseller', 'taluk', 'taluka' => 'taluk', 'zonal' => 'zonal', 'district' => 'district',
            'regional' => 'regional', 'wholesaler' => 'wholesaler', 'area dealer', 'area distributor' => 'area_dealer',
            'sub dealer' => 'sub_dealer', default => null,
        };
        $rows = $this->legacy->table('ci_users')->orderBy('id')->get();
        $bridByMapid = $rows->filter(fn ($u) => $u->mapid !== '' && $u->brid !== '')->pluck('brid', 'mapid')->all();
        $takenEmails = User::withTrashed()->pluck('id', 'email')->all();
        $byCode = User::withTrashed()->whereNotNull('member_code')->get()->keyBy('member_code');
        $margins = [];   // branch id => summed margins

        foreach ($rows as $u) {
            if ((int) $u->role === 1 || (int) $u->is_admin === 1) {
                continue;   // the head-office super admin already exists
            }
            $branchId = $u->brid !== '' ? ($this->branchMap[(int) $u->brid] ?? null) : null;
            if (! $branchId) {
                $this->warnings[] = "ci_users #{$u->id} ({$u->username}) has no branch — login skipped";
                $skip++;
                continue;
            }
            $code = $this->clip($u->mapid ?: null, 30);
            $name = $this->clip(trim($u->firstname . ' ' . $u->lastname) ?: $u->username, 200);
            $email = Str::lower(trim((string) $u->email));
            $existing = $code ? $byCode->get($code) : null;
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || (isset($takenEmails[$email]) && (! $existing || $existing->email !== $email))) {
                $email = Str::slug($u->username ?: ('dealer' . $u->id), '.') . '@dealer.lordicl.local';
                if (isset($takenEmails[$email])) {
                    $email = Str::slug($u->username, '.') . '.' . $u->id . '@dealer.lordicl.local';
                }
            }
            $attrs = [
                'name' => $name,
                'email' => $email,
                'phone' => $this->clip($u->mobile_no ?: null, 20),
                'status' => (int) $u->is_active === 1 ? 'active' : 'inactive',
                'branch_id' => $branchId,
                'designation' => $this->clip($u->desiname ?: null, 60),
                'member_code' => $code,
                'invested' => min($this->num($u->invested), 999999999999.99),
                'locale' => 'en', 'currency_code' => 'INR',
            ];
            if ($existing) {
                $existing->forceFill($attrs)->save();
                $user = $existing;
            } else {
                $user = new User($attrs);
                $user->forceFill(['password' => $u->password]);   // bcrypt hash kept as-is (hashed cast)
                $user->created_at = $this->date($u->created_at) ?? now();
                $user->save();
                $byCode[$code ?? 'u' . $user->id] = $user;
            }
            $takenEmails[$email] = $user->id;
            $user->syncRoles(['distributor']);
            $this->userMap[(int) $u->id] = $user->id;

            // branch: level, margins, digi cash, source
            $level = $levelOf($u->desiname);
            $m = &$margins[$branchId];
            $m ??= ['bill_margin' => 0, 'gold_gm_margin' => 0, 'silver_gm_margin' => 0, 'stock_trans_margin' => 0, 'digi_cash_balance' => 0, 'level' => null, 'source' => null, 'lat' => null, 'lng' => null];
            $m['bill_margin'] += $this->num($u->billmargin);
            $m['gold_gm_margin'] += $this->num($u->goldgmmargin);
            $m['silver_gm_margin'] += $this->num($u->silvergmmargin);
            $m['stock_trans_margin'] += $this->num($u->stocktransmargin);
            $m['digi_cash_balance'] += $this->num($u->digicash);
            $m['level'] ??= $level;
            if ($u->uplineid !== '' && isset($bridByMapid[$u->uplineid])) {
                $m['source'] ??= $this->branchMap[(int) $bridByMapid[$u->uplineid]] ?? null;
            }
            if (is_numeric($u->latitude) && (float) $u->latitude != 0.0) {
                $m['lat'] ??= (float) $u->latitude;
                $m['lng'] ??= (float) $u->longitude;
            }
            unset($m);
            $in++;
        }

        foreach ($margins as $branchId => $m) {
            $upd = [
                'bill_margin' => round($m['bill_margin'], 3), 'gold_gm_margin' => round($m['gold_gm_margin'], 2),
                'silver_gm_margin' => round($m['silver_gm_margin'], 2), 'stock_trans_margin' => round($m['stock_trans_margin'], 3),
                'digi_cash_balance' => round($m['digi_cash_balance'], 2),
            ];
            if ($m['level']) {
                $upd['level'] = $m['level'];
            }
            if ($m['source'] && $m['source'] !== $branchId) {
                $upd['source_branch_id'] = $m['source'];
            }
            if ($m['lat']) {
                $upd['latitude'] = $m['lat'];
                $upd['longitude'] = $m['lng'];
            }
            Branch::withoutEvents(fn () => DB::table('branches')->where('id', $branchId)->update($upd));
        }
        // HQ: the super admin's accrued figures are company figures — not a dealer margin.
        $this->note('dealer logins', $in, $skip);
    }

    // ------------------------------------------------ single-line history

    /** IC (5.2 lakh legacy rows) → one ledger line per member per status. */
    protected function importIcCommissions(): void
    {
        [$in, $skip] = [0, 0];
        $batch = [];
        $rows = $this->legacy->table('tbl_iccom')
            ->selectRaw('comusername, UPPER(status) st, COUNT(*) n, SUM(comamount) amt, MIN(billdate) d0, MAX(billdate) d1')
            ->groupBy('comusername', 'st')->get();
        foreach ($rows as $r) {
            $memberId = $this->memberMap[$this->clip($r->comusername, 30)] ?? null;
            if (! $memberId) {
                $skip++;
                continue;
            }
            $status = match ($r->st) { 'PAID' => 'paid', 'PASSED' => 'approved', default => 'pending' };
            $amount = round((float) $r->amt, 2);
            $date = $this->date($r->d1) ?? '2020-01-01';
            $batch[] = [
                'type' => 'IC', 'member_id' => $memberId, 'from_member_id' => null, 'bond_id' => null,
                'invoice_no' => $this->legacyRef('LEGACY-IC', (int) $r->n, $r->d0, $r->d1),
                'level' => null, 'amount' => $amount, 'status' => $status, 'pay_via' => null,
                'earned_on' => $date, 'paid_on' => $status === 'paid' ? $date : null, 'branch_id' => null,
                'tds' => 0, 'service_charge' => 0, 'net_amount' => $amount,
                'created_at' => $date, 'updated_at' => $date,
            ];
            $in++;
        }
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table('commission_ledger')->insert($chunk);
        }
        $this->note('commission_ledger IC (1 line / member / status)', $in, $skip);
    }

    /** GAP = the monthly payout log (net, TDS, service charge) → one line per member per status. */
    protected function importGapCommissions(): void
    {
        [$in, $skip] = [0, 0];
        $batch = [];
        $rows = $this->legacy->table('tbl_gap_log')
            ->selectRaw('username, UPPER(status) st, COUNT(*) n, SUM(nettotal) amt, SUM(tds) tds, SUM(servicetax) sc, SUM(grandtotal) net, MIN(date) d0, MAX(date) d1')
            ->groupBy('username', 'st')->get();
        foreach ($rows as $r) {
            $memberId = $this->memberMap[$this->clip($r->username, 30)] ?? null;
            if (! $memberId) {
                $skip++;
                continue;
            }
            $status = $r->st === 'PAID' ? 'paid' : 'pending';
            $date = $this->date($r->d1) ?? '2020-01-01';
            $batch[] = [
                'type' => 'GAP', 'member_id' => $memberId, 'from_member_id' => null, 'bond_id' => null,
                'invoice_no' => $this->legacyRef('LEGACY-GAP', (int) $r->n, $r->d0, $r->d1),
                'level' => null, 'amount' => round((float) $r->amt, 2), 'status' => $status, 'pay_via' => null,
                'earned_on' => $date, 'paid_on' => $status === 'paid' ? $date : null, 'branch_id' => null,
                'tds' => round((float) $r->tds, 2), 'service_charge' => round((float) $r->sc, 2),
                'net_amount' => round((float) $r->net, 2),
                'created_at' => $date, 'updated_at' => $date,
            ];
            $in++;
        }
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table('commission_ledger')->insert($chunk);
        }
        $this->note('commission_ledger GAP (1 line / member / status)', $in, $skip);
    }

    /** Dealer billing margin (53k rows) → one line per dealer branch. */
    protected function importResellerCommissions(): void
    {
        [$in, $skip] = [0, 0];
        $usersByCode = User::whereNotNull('member_code')->pluck('id', 'member_code')->all();
        $batch = [];
        $rows = $this->legacy->table('tbl_reseller_com')
            ->selectRaw('userid, brid, UPPER(status) st, COUNT(*) n, SUM(comvalue) amt, MIN(billdate) d0, MAX(billdate) d1')
            ->groupBy('userid', 'brid', 'st')->get();
        foreach ($rows as $r) {
            $branchId = $this->branchMap[(int) $r->brid] ?? null;
            $code = $this->clip($r->userid, 30);
            if (! $branchId && ! isset($usersByCode[$code])) {
                $skip++;
                continue;
            }
            $status = match ($r->st) { 'PAID' => 'paid', 'PASSED' => 'passed', default => 'pending' };
            $date = $this->date($r->d1) ?? '2020-01-01';
            $batch[] = [
                'bill_date' => $date,
                'invoice_no' => $this->legacyRef('LEGACY-BM', (int) $r->n, $r->d0, $r->d1),
                'com_type_id' => 1,
                'user_id' => $usersByCode[$code] ?? null,
                'branch_id' => $branchId,
                'mapped_uid' => $code,
                'com_value' => round((float) $r->amt, 2),
                'reference_member_id' => $this->memberMap[$code] ?? null,
                'status' => $status,
                'paid_on' => $status === 'paid' ? $date : null,
                'tds' => 0, 'service_charge' => 0, 'net_amount' => round((float) $r->amt, 2),
                'created_at' => $date, 'updated_at' => $date,
            ];
            $in++;
        }
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table('reseller_commissions')->insert($chunk);
        }
        $this->note('reseller_commissions (1 line / dealer)', $in, $skip);
    }

    /**
     * Digi gold: purchases (1.3 lakh) minus withdrawals (47k, every status — legacy deducts at
     * request time) → one OPENING line per member + the wallet gram balance.
     */
    protected function importDigiOpening(): void
    {
        $buy = [];
        foreach ($this->legacy->table('tbl_order_digiunique')
            ->selectRaw('cus_type code, COUNT(*) n, SUM(gold_wt) g, SUM(gold_pur) v, MAX(rateondate) rate, MIN(transdate) d0, MAX(transdate) d1')
            ->groupBy('cus_type')->get() as $r) {
            $buy[$this->clip($r->code, 30)] = $r;
        }
        $wd = [];
        foreach ($this->legacy->table('tbl_digiwithdrawal')
            ->selectRaw('custype code, COUNT(*) n, SUM(wdgoldgm) g, SUM(wdgoldworth) v')
            ->groupBy('custype')->get() as $r) {
            $wd[$this->clip($r->code, 30)] = $r;
        }

        [$in, $skip, $neg] = [0, 0, 0];
        $batch = [];
        $wallet = [];
        foreach (array_unique(array_merge(array_keys($buy), array_keys($wd))) as $code) {
            $memberId = $this->memberMap[$code] ?? null;
            if (! $memberId) {
                $skip++;
                continue;
            }
            $b = $buy[$code] ?? null;
            $w = $wd[$code] ?? null;
            $grams = round((float) ($b->g ?? 0) - (float) ($w->g ?? 0), 6);
            $worth = round((float) ($b->v ?? 0) - (float) ($w->v ?? 0), 2);
            if ($grams < -0.000001) {
                $neg++;
                $this->warnings[] = sprintf('Digi: %s withdrew more than bought (%.4f g) — opening clamped to 0', $code, $grams);
                $grams = 0.0;
                $worth = max(0.0, $worth);
            }
            $date = $this->date($b->d1 ?? null) ?? '2020-01-01';
            $batch[] = [
                'ref_no' => $this->clip('LEGACY-' . $code, 40),
                'member_id' => $memberId,
                'source' => 'OPENING',
                'gold_wt' => $grams,
                'value' => $worth,
                'rate_on_date' => $this->num($b->rate ?? 0),
                'branch_id' => null,
                'created_at' => $date, 'updated_at' => $date,
            ];
            $wallet[$memberId] = $grams;
            $in++;
        }
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table('digi_orders')->insert($chunk);
        }
        // The app's Digi Market purse reads member_wallets.digi_gold_grams and shows the
        // gram ledger behind it (digi_gold_txns) — one "legacy_opening" credit per member.
        $txns = [];
        foreach ($batch as $row) {
            if ((float) $row['gold_wt'] <= 0) {
                continue;
            }
            $txns[] = [
                'member_id' => $row['member_id'], 'metal' => 'gold', 'type' => 'credit',
                'grams' => $row['gold_wt'], 'rate' => $row['rate_on_date'], 'value' => $row['value'], 'fee' => 0,
                'source' => 'legacy_opening', 'reference' => $row['ref_no'], 'balance_after' => $row['gold_wt'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ];
        }
        if (Schema::hasTable('digi_gold_txns')) {
            DB::table('digi_gold_txns')->where('source', 'legacy_opening')->delete();
            foreach (array_chunk($txns, 1000) as $chunk) {
                DB::table('digi_gold_txns')->insert($chunk);
            }
        }
        foreach (array_chunk($wallet, 1000, true) as $chunk) {
            foreach ($chunk as $memberId => $grams) {
                DB::table('member_wallets')->updateOrInsert(['member_id' => $memberId],
                    ['digi_gold_grams' => $grams, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        $this->note('digi opening (1 line / member)', $in, $skip + $neg);
    }

    /** Only the PENDING queue rows are actionable; redeemed history is in the opening line. */
    protected function importDigiQueue(): void
    {
        [$in, $skip] = [0, 0];
        $this->legacy->table('tbl_digi_queue')->where('deliverystatus', 'PENDING')->orderBy('trid')
            ->chunk(1000, function ($rows) use (&$in, &$skip) {
                $batch = [];
                foreach ($rows as $q) {
                    $memberId = $this->memberMap[$this->clip($q->userid, 30)] ?? null;
                    if (! $memberId) {
                        $skip++;
                        continue;
                    }
                    $date = $this->date($q->dot) ?? '2020-01-01';
                    $mode = Str::lower((string) $q->qrmode);
                    $batch[] = [
                        'member_id' => $memberId,
                        'qr_code' => $this->clip($q->qrcode ?: ('Q' . $q->trid), 16),
                        'qr_mode' => in_array($mode, ['cash', 'gold', 'silver'], true) ? $mode : 'cash',
                        'gram_worth' => $this->num($q->gm_worth),
                        'cash_worth' => $this->num($q->cash_worth),
                        'reference' => $this->clip($q->refferance ?: null, 40),
                        'route' => $this->clip($q->route ?: null, 40),
                        'delivery_status' => 'pending',
                        'qr_sent' => (int) $q->qrsend === 1,
                        'redeem_branch_id' => $this->branchMap[(int) $q->redeembranchid] ?? null,
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('digi_queue')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('digi_queue (pending only)', $in, $skip);
    }

    /** Pending withdrawals stay as rows (they still need delivery); passed ones are netted. */
    protected function importDigiWithdrawals(): void
    {
        [$in, $skip] = [0, 0];
        $batch = [];
        foreach ($this->legacy->table('tbl_digiwithdrawal')->where('status', 'PENDING')->orderBy('wdid')->get() as $w) {
            $memberId = $this->memberMap[$this->clip($w->custype, 30)] ?? null;
            if (! $memberId) {
                $skip++;
                continue;
            }
            $date = $this->date($w->wddate) ?? '2020-01-01';
            $batch[] = [
                'member_id' => $memberId, 'gold_wt' => $this->num($w->wdgoldgm), 'worth' => $this->num($w->wdgoldworth),
                'status' => 'pending', 'created_at' => $date, 'updated_at' => $date,
            ];
            $in++;
        }
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table('digi_withdrawals')->insert($chunk);
        }
        $this->note('digi_withdrawals (pending only)', $in, $skip);
    }

    protected function legacyRef(string $prefix, int $n, $from, $to): string
    {
        $f = $this->date($from);
        $t = $this->date($to);
        $span = $f && $t ? substr($f, 0, 7) . '→' . substr($t, 0, 7) : '';

        return $this->clip(trim(sprintf('%s ×%s %s', $prefix, number_format($n), $span)), 40);
    }

    // ---------------------------------------------------------------- dry run

    protected function dryRun(): int
    {
        $L = $this->legacy;
        $c = fn (string $t, ?\Closure $w = null) => number_format(($w ? $w($L->table($t)) : $L->table($t))->count());
        $this->info('Legacy source: ' . config('database.connections.legacy.database'));
        $this->table(['legacy table', 'rows', '→ target'], [
            ['tbl_member', $c('tbl_member'), 'members (row-level, + branch + rank)'],
            ['tbl_branches', $c('tbl_branches'), 'branches (brid 1 → HQ Sivakasi; rest kind=dealer)'],
            ['ci_users', $c('ci_users'), 'users (distributor logins, passwords kept)'],
            ['tbl_planjewel', $c('tbl_planjewel'), 'plans (matched by name to the v2 sheet)'],
            ['tbl_bond', $c('tbl_bond'), 'bonds (row-level — contracts)'],
            ['tbl_rdentry', $c('tbl_rdentry'), 'rd_entries (row-level)'],
            ['tbl_cbc', $c('tbl_cbc'), 'cbc_entries (row-level — coupons are redeemable)'],
            ['tbl_salesinvoice / cart', $c('tbl_salesinvoice') . ' / ' . $c('tbl_sales_cart'), 'sales_invoices / sale_lines (row-level)'],
            ['tbl_iccom', $c('tbl_iccom'), number_format($L->table('tbl_iccom')->distinct()->count('comusername')) . ' members → ≤2 IC lines each'],
            ['tbl_gap_log', $c('tbl_gap_log'), number_format($L->table('tbl_gap_log')->distinct()->count('username')) . ' members → ≤2 GAP lines each'],
            ['tbl_reseller_com', $c('tbl_reseller_com'), number_format($L->table('tbl_reseller_com')->distinct()->count('userid')) . ' dealers → 1 margin line each'],
            ['tbl_order_digiunique', $c('tbl_order_digiunique'), number_format($L->table('tbl_order_digiunique')->distinct()->count('cus_type')) . ' members → 1 OPENING line each'],
            ['tbl_digiwithdrawal', $c('tbl_digiwithdrawal'), $c('tbl_digiwithdrawal', fn ($q) => $q->where('status', 'PENDING')) . ' pending kept as rows'],
            ['tbl_digi_queue', $c('tbl_digi_queue'), $c('tbl_digi_queue', fn ($q) => $q->where('deliverystatus', 'PENDING')) . ' pending kept as rows'],
        ]);
        $this->line('Legacy HQ: ' . json_encode($L->table('tbl_branches')->where('brid', 1)->first()));

        return self::SUCCESS;
    }
}
