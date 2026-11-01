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
 *  - Commission history is imported ROW BY ROW (board 2026-10-31: "don't merge commission
 *    from legacy — show the complete earning data"):
 *      · Sales Incentive (IC, 5.2 lakh rows)   → one ledger line per legacy row
 *      · Turnover salary (GAP payout log)      → one line per monthly payout (TDS/SC kept)
 *      · Dealer billing margin (53k rows)      → one line per legacy row
 *    Only Digi gold (1.3 lakh buys / 47k draws) is summarised: one OPENING balance line
 *    per member (the purse balance is what matters; the legacy rows carry no documents).
 *  - CBC coupons, bonds, Gold Saving instalments, sales invoices and their lines stay
 *    row-level: coupons are individually redeemable and contracts/invoices are legal documents.
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

    protected $description = 'ROLL-OUT import of the legacy lordicl database (Sivakasi HQ, v2 schemes, dealer logins, row-level commission history)';

    /** legacy ci_users.id => new users.id */
    protected array $userMap = [];

    /** legacy ci_users.id => legacy brid (string) */
    protected array $userBranch = [];

    /** legacy brid => new branch id (parent's $branchMap is keyed the same way) */
    protected array $warnings = [];

    public function handle(): int
    {
        \App\Services\Activity\ActivityLogger::$enabled = false;   // bulk import: no per-row activity rows
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
        $this->importOrderRequests();

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

    /**
     * Pending dealer purchase orders (legacy tbl_brorderrequest status PENDING + their
     * tbl_br_purchase_cart lines, route BO/RO). In legacy these are mostly cash-stock top-ups
     * (the dealer buys the CASH product from its supplier, pays by bank, HQ approves → the
     * dealer's cash stock rises) plus coin orders. They land as PENDING order requests
     * (source "legacy", request_no LEG-<transid>) so the supplier approves or rejects them on
     * Order Requests exactly like a new-system order. Legacy "RO" = payment received; the
     * paid amount and the legacy expiry are carried in the request for the approver.
     * Approved/closed legacy orders are history (their stock is already in tbl_stock) — not imported.
     */
    protected function importOrderRequests(): void
    {
        [$in, $skip] = [0, 0];
        $products = DB::table('catalog_products')->select('id', 'code', 'material')->get()->keyBy('code');
        $userByBranch = DB::table('users')->whereNotNull('branch_id')->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'branch_id'])->groupBy('branch_id')->map(fn ($g) => (int) $g->first()->id)->all();
        $hqUser = (int) (DB::table('users')->orderBy('id')->value('id') ?? 0);
        $lines = [];
        foreach ($this->legacy->table('tbl_br_purchase_cart')->whereIn('route', ['BO', 'RO'])
            ->whereIn('pono', fn ($q) => $q->select('transid')->from('tbl_brorderrequest')->where('status', 'PENDING'))
            ->orderBy('cartid')->get() as $c) {
            $lines[(int) $c->pono][] = $c;
        }
        foreach ($this->legacy->table('tbl_brorderrequest')->where('status', 'PENDING')->orderBy('transid')->get() as $r) {
            $branchId = $this->branchMap[(int) $r->brid] ?? null;
            $cart = $lines[(int) $r->transid] ?? [];
            if (! $branchId || ! $cart) {
                $this->warnings[] = sprintf('order request %d (%s, ₹%s) skipped: %s', $r->transid, $r->doorder, number_format($this->num($r->nettotal), 2),
                    $branchId ? 'no order lines in legacy' : "ordering branch brid {$r->brid} not imported");
                $skip++;
                continue;
            }
            $paid = strtoupper(trim((string) $r->paymentflag)) === 'RO';
            $grand = round($this->num($r->nettotal), 2);
            $cross = round($this->num($r->ordercost) ?: $grand, 2);
            $placed = $this->date(substr((string) $r->placement, 0, 10)) ?? $this->date($r->doorder) ?? now()->toDateString();
            $remarks = trim((string) $r->paymentremarks);
            $remarks = ($remarks !== '' ? $remarks . ' · ' : '') . 'Legacy order ' . $r->transid . ' of ' . $r->doorder
                . ($paid ? ' · payment received in legacy (RO)' : ' · payment NOT confirmed in legacy')
                . ($r->expiry ? ' · legacy expiry ' . substr((string) $r->expiry, 0, 10) : '');
            $requestId = DB::table('branch_order_requests')->insertGetId([
                'request_no' => 'LEG-' . $r->transid,
                'source' => 'legacy',
                'source_ref' => (string) $r->transid,
                'branch_id' => $branchId,
                'requested_by' => $userByBranch[$branchId] ?? ($hqUser ?: null),
                'no_of_items' => count($cart),
                'cross_total' => $cross,
                'gst_total' => round(max(0, $grand - $cross), 2),
                'grand_total' => $grand,
                'payment_type' => match (strtolower(trim((string) $r->paymode))) {
                    'cashmode', 'cash' => 'cash', 'cheque' => 'cheque', 'online', 'bank', 'upi', 'neft', 'rtgs' => 'online', 'digicash', 'digi_cash' => 'digi_cash', default => 'others',
                },
                'payment_remarks' => $this->clip($remarks, 255),
                'paid_amount' => $paid ? $grand : 0,
                'pay_cash' => $paid ? $grand : 0,
                'status' => 'pending',
                'created_at' => $placed . ' 00:00:00', 'updated_at' => now(),
            ]);
            $batch = [];
            foreach ($cart as $c) {
                $p = $products[$this->clip((string) $c->prid, 40)] ?? null;
                $batch[] = [
                    'order_request_id' => $requestId,
                    'catalog_product_id' => $p?->id,
                    'material' => $p?->material ?? strtolower(trim((string) $c->catename) ?: 'gold'),
                    'description' => $this->clip(trim((string) $c->prname) ?: null, 200),
                    'weight' => round($this->num($c->weight), 4),
                    'rate' => round($this->num($c->purprice), 2),
                    'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0,
                    'gst_pct' => ($p?->material ?? '') === 'cash' ? 0 : round($this->num($r->gst), 2),
                    'line_total' => round($this->num($c->aftercharges) ?: $this->num($c->weight) * $this->num($c->purprice), 2),
                ];
                if (! $p) {
                    $this->warnings[] = sprintf('order request %d: product prid %s (%s) not in the catalog — line kept without a product', $r->transid, $c->prid, $c->prname);
                }
            }
            DB::table('branch_order_lines')->insert($batch);
            $in++;
        }
        $this->note('order requests (pending)', $in, $skip);
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
            $branchId = $u->brid !== '' ? ($this->branchMap[(int) $u->brid] ?? null) : null;
            if ((int) $u->role === 1 || (int) $u->is_admin === 1) {
                // the head-office super admin already exists as a login — but its ci_users row
                // carries the Head Office's own digi cash / margins (₹2.96 L digi cash in the
                // Oct-2026 closing), which must still land on branch #1.
                if ($branchId) {
                    $m = &$margins[$branchId];
                    $m ??= ['bill_margin' => 0, 'gold_gm_margin' => 0, 'silver_gm_margin' => 0, 'stock_trans_margin' => 0, 'digi_cash_balance' => 0, 'level' => null, 'source' => null, 'lat' => null, 'lng' => null];
                    $m['bill_margin'] += max(0.0, $this->num($u->billmargin));   // negatives reset to 0 (board 2026-11-01)
                    $m['gold_gm_margin'] += $this->num($u->goldgmmargin);
                    $m['silver_gm_margin'] += $this->num($u->silvergmmargin);
                    $m['stock_trans_margin'] += $this->num($u->stocktransmargin);
                    $m['digi_cash_balance'] += $this->num($u->digicash);
                    unset($m);
                }
                continue;
            }
            if (! $branchId) {
                $this->warnings[] = sprintf('ci_users #%d (%s) has no branch — login skipped (billing %s, digi cash %s not carried)', $u->id, $u->username, number_format($this->num($u->billmargin), 2), number_format($this->num($u->digicash), 2));
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
            // Board 2026-11-01: a NEGATIVE legacy billing margin is a leftover of a system collapse,
            // not a debt — it resets to 0 (102 rows, −₹14.26 L). Digi cash is the branch balance, kept as is.
            if ($this->num($u->billmargin) < 0) {
                $this->warnings[] = sprintf('ci_users #%d (%s): negative billing margin %s reset to 0 (board 2026-11-01)', $u->id, $u->username, number_format($this->num($u->billmargin), 2));
            }
            $m['bill_margin'] += max(0.0, $this->num($u->billmargin));
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
            // Head Office keeps level = hq and no supplier: the legacy admin login (LJW01) carries a
            // dealership designation that must not demote branch #1 (seen 2026-10-31 after a rebuild).
            $isHq = (int) $branchId === (int) ($this->branchMap[1] ?? 0);
            if ($m['level'] && ! $isHq) {
                $upd['level'] = $m['level'];
            }
            if ($m['source'] && $m['source'] !== $branchId && ! $isHq) {
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

    // ------------------------------------------------ commission history (row-level)

    // Board 2026-10-31 (item 11 / common 5): "don't merge commission from legacy — show the
    // complete earning data". IC and dealer-margin rows are imported one-for-one by the
    // parent importer (LegacyImport::importIcCommissions / importResellerCommissions);
    // GAP comes from the monthly payout log below, one line per month with its TDS and
    // service charge — nothing is compressed into a single line any more.

    /** GAP = tbl_gap_log, ONE LINE PER MONTHLY PAYOUT (net, TDS, service charge kept). */
    protected function importGapCommissions(): void
    {
        [$in, $skip] = [0, 0];
        $this->legacy->table('tbl_gap_log')->orderBy('id')
            ->chunk(2000, function ($rows) use (&$in, &$skip) {
                $batch = [];
                foreach ($rows as $r) {
                    $memberId = $this->memberMap[$this->clip($r->username, 30)] ?? null;
                    if (! $memberId) {
                        $skip++;
                        continue;
                    }
                    $status = strtoupper((string) $r->status) === 'PAID' ? 'paid' : 'pending';
                    $date = $this->date($r->date) ?? '2020-01-01';
                    $batch[] = [
                        'type' => 'GAP', 'member_id' => $memberId, 'from_member_id' => null, 'bond_id' => null,
                        'invoice_no' => $this->clip('GAP ' . ($r->remarks ?: substr($date, 0, 7)) . ' ×' . (int) $r->pmcount, 40),
                        'level' => null, 'amount' => $this->num($r->nettotal), 'status' => $status, 'pay_via' => null,
                        'earned_on' => $date, 'paid_on' => $status === 'paid' ? $date : null, 'branch_id' => null,
                        'tds' => $this->num($r->tds), 'service_charge' => $this->num($r->servicetax),
                        'net_amount' => $this->num($r->grandtotal),
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('commission_ledger')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('commission_ledger GAP (row-level, monthly payout log)', $in, $skip);
    }

    /**
     * Digi gold: purchases (1.3 lakh) minus withdrawals (47k, every status — legacy deducts at
     * request time) → one OPENING line per member in digi_orders, status PENDING.
     * Board 2026-11-01 (night): NOTHING is pushed into the purse here — Head Office sends each
     * balance from Commissions → Pending Digi (PendingDigiService writes the legacy_opening
     * ledger line + the wallet grams at that moment).
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
                'status' => 'pending',
                'gold_wt' => $grams,
                'value' => $worth,
                'rate_on_date' => $this->num($b->rate ?? 0),
                'branch_id' => null,
                'created_at' => $date, 'updated_at' => $date,
            ];
            $in++;
        }
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table('digi_orders')->insert($chunk);
        }
        // Purse stays empty until Pending Digi sends the balance; clear any earlier push.
        if (Schema::hasTable('digi_gold_txns')) {
            DB::table('digi_gold_txns')->where('source', 'legacy_opening')->delete();
        }
        DB::table('member_wallets')->where('digi_gold_grams', '!=', 0)->update(['digi_gold_grams' => 0, 'updated_at' => now()]);
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
                        'rate' => $this->num($q->goldprice) ?: null,   // metal price on the QR's date (board: redeem at that price)
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
            ['tbl_iccom', $c('tbl_iccom'), 'commission_ledger IC (row-level — never merged)'],
            ['tbl_gap_log', $c('tbl_gap_log'), 'commission_ledger GAP (row-level — one line per monthly payout)'],
            ['tbl_reseller_com', $c('tbl_reseller_com'), 'reseller_commissions (row-level)'],
            ['tbl_order_digiunique', $c('tbl_order_digiunique'), number_format($L->table('tbl_order_digiunique')->distinct()->count('cus_type')) . ' members → 1 OPENING line each'],
            ['tbl_digiwithdrawal', $c('tbl_digiwithdrawal'), $c('tbl_digiwithdrawal', fn ($q) => $q->where('status', 'PENDING')) . ' pending kept as rows'],
            ['tbl_digi_queue', $c('tbl_digi_queue'), $c('tbl_digi_queue', fn ($q) => $q->where('deliverystatus', 'PENDING')) . ' pending kept as rows'],
        ]);
        $this->line('Legacy HQ: ' . json_encode($L->table('tbl_branches')->where('brid', 1)->first()));

        return self::SUCCESS;
    }
}
