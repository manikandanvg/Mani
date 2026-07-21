<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Category;
use App\Models\LiveRate;
use App\Models\Mou;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full ETL of the legacy CodeIgniter `lordicl` database (connection "legacy")
 * into the new schema — unlike LegacyDemoSeeder this imports ALL members and
 * the complete transactional history (bonds, IC/GAP commissions, CBC, RD,
 * sales invoices, digi gold) so real-volume performance can be evaluated.
 *
 * Master data is idempotent (natural keys). Transactional tables are bulk
 * imported and require --fresh to wipe previous contents first. Legacy primary
 * keys are preserved on bonds (bondid) and sales_invoices (estid) so child
 * rows can link without lookup tables.
 *
 *   php artisan legacy:import --fresh
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import {--fresh : Wipe transactional target tables before importing}';

    protected $description = 'Import the entire legacy lordicl database (members + transaction history) into the new schema';

    protected $legacy;

    /** @var array<int|string,int> */
    protected array $branchMap = [];
    protected array $planMap = [];      // legacy planid => plan id
    protected array $productMap = [];   // legacy prid => catalog_product id
    protected array $mouMap = [];       // legacy mouid => mou id
    protected array $memberMap = [];    // member_code => member id
    protected array $legacyIdToCode = []; // tbl_member.id => userid
    protected array $catMap = [];
    protected array $subcatMap = [];

    protected array $stats = [];

    public function handle(): int
    {
        $this->legacy = DB::connection('legacy');
        DB::connection()->disableQueryLog();
        $start = microtime(true);

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
        $this->importPlans();
        $this->importBranches();
        $this->importCategories();
        $this->importCatalogProducts();
        $this->importStock();
        $this->importMembers();
        $this->linkMemberNetwork();
        $this->importWallets();
        $this->importCustomers();
        $this->importBonds();
        $this->importRdEntries();
        $this->importCbcEntries();
        $this->importIcCommissions();
        $this->importGapCommissions();
        $this->importResellerCommissions();
        $this->importSalesInvoices();
        $this->importSaleLines();
        $this->importDigiOrders();
        $this->importDigiQueue();
        $this->importDigiWithdrawals();
        $this->importEpinLogs();

        $this->table(['step', 'imported', 'skipped'], collect($this->stats)
            ->map(fn ($s, $k) => [$k, $s['in'], $s['skip']])->values()->all());
        $this->info(sprintf('Legacy full import complete in %.1fs', microtime(true) - $start));

        return self::SUCCESS;
    }

    protected function wipeTransactional(): void
    {
        $this->warn('Wiping transactional target tables…');
        Schema::disableForeignKeyConstraints();
        foreach ([
            'redemption_lines', 'redemption_invoices', 'redeemable_qrs', 'member_contracts',
            'sale_lines', 'sales_invoices', 'commission_ledger', 'cbc_entries', 'rd_entries',
            'rd_mandates', 'reseller_commissions', 'digi_orders', 'digi_queue',
            'digi_withdrawals', 'epin_logs', 'epins', 'bonds', 'customers',
        ] as $t) {
            DB::table($t)->truncate();
        }
        Schema::enableForeignKeyConstraints();
    }

    // ------------------------------------------------------------------ master

    protected function importLiveRate(): void
    {
        $r = $this->legacy->table('tbl_liverate')->first();
        if ($r) {
            LiveRate::updateOrCreate(
                ['country' => 'IN'],
                ['gold' => $this->num($r->gold), 'silver' => $this->num($r->silver),
                 'diamond' => $this->num($r->diamond), 'source' => 'manual',
                 'is_override' => true, 'effective_at' => now()],
            );
        }
    }

    protected function importMous(): void
    {
        foreach ($this->legacy->table('tbl_mou')->get() as $m) {
            $title = $m->moutitle ?: ('MOU ' . $m->mouid);
            $mou = Mou::whereRaw("JSON_UNQUOTE(JSON_EXTRACT(title,'$.en')) = ?", [$title])->first()
                ?? Mou::create([
                    'title' => ['en' => $title],
                    'terms' => ['en' => (string) ($m->moudata ?? '')],
                    'duration_months' => 12,
                ]);
            $this->mouMap[$m->mouid] = $mou->id;
        }
    }

    protected function importPlans(): void
    {
        foreach ($this->legacy->table('tbl_planjewel')->get() as $p) {
            $type = (int) $p->plantypeid;
            $plan = Plan::updateOrCreate(
                ['code' => 'P' . $p->planid],
                [
                    'name' => ['en' => $p->planname],
                    'plan_type' => $type ?: 1,
                    'type' => match ($type) { 1 => 'rd', 2 => 'digital', 3 => 'gold', 4 => 'silver', default => 'others' },
                    'min_value' => $this->num($p->minvalue),
                    'allocation_pct' => $this->num($p->allocation),
                    'validity_months' => (int) ($p->levelcomduration ?: 12),
                    'cbc_value' => $this->num($p->cbvalue),
                    'cbc_count' => (int) $p->cbcount,
                    'level_depth' => (int) $p->leveldepth,
                    'ic_schedule' => $this->numbers($p->iccom),
                    'level_schedule' => $this->numbers($p->levelcom),
                    'level_com_duration' => (int) $p->levelcomduration,
                    'billing_margin' => $this->num($p->billingmargin),
                    'gm_margin' => $this->num($p->gmmargin),
                    'stock_trans_margin' => $this->num($p->stocktransmargin),
                    'epin_count' => (int) $p->epincount,
                    'mou_id' => $this->mouMap[$p->moutype] ?? null,
                    'is_active' => (string) $p->planvisible === '1',
                ],
            );
            $this->planMap[$p->planid] = $plan->id;
        }
    }

    protected function importBranches(): void
    {
        $this->branchMap[1] = Branch::min('id');
        $byName = Branch::pluck('id', 'name');

        foreach ($this->legacy->table('tbl_branches')->get() as $b) {
            if ((int) $b->brid === 1) {
                continue;
            }
            $name = $this->clip($b->storename ?: ('Branch ' . $b->brid), 200);
            $id = $byName[$name] ?? Branch::create([
                'name' => $name,
                'address' => $this->clip($b->address, 255), 'city' => $this->clip($b->city, 120),
                'pincode' => $this->clip($b->pincode, 12),
                'country' => 'IN', 'phone' => $this->clip($b->phoneno, 20),
                'incharge' => $this->clip($b->brincharge, 200),
                'gst_no' => $this->clip($b->gstno, 25), 'is_active' => (int) $b->status === 1,
            ])->id;
            $this->branchMap[(int) $b->brid] = $id;
        }
    }

    protected function importCategories(): void
    {
        foreach ($this->legacy->table('tbl_category')->get() as $c) {
            $mat = $this->materialOf($c->catename);
            if (! in_array($mat, ['gold', 'silver'])) {
                continue;
            }
            $cat = Category::firstOrCreate(
                ['slug' => 'trade-' . strtolower($c->catename)],
                ['name' => ['en' => ucfirst(strtolower($c->catename))], 'domain' => 'trade', 'material' => $mat],
            );
            $this->catMap[$c->cateid] = $cat->id;
        }

        $subs = $this->legacy->table('tbl_stock')
            ->select('cateid', 'catename', 'scateid', 'scatename')
            ->whereIn('catename', ['GOLD', 'SILVER'])
            ->distinct()->get();
        foreach ($subs as $s) {
            if (! $s->scatename || ! isset($this->catMap[$s->cateid])) {
                continue;
            }
            $sub = Category::firstOrCreate(
                ['slug' => 'trade-sub-' . strtolower($s->scatename)],
                ['name' => ['en' => ucfirst(strtolower($s->scatename))], 'domain' => 'trade',
                 'material' => $this->materialOf($s->catename), 'parent_id' => $this->catMap[$s->cateid]],
            );
            $this->subcatMap[$s->scateid] = $sub->id;
        }
    }

    protected function importCatalogProducts(): void
    {
        $rows = $this->legacy->table('tbl_stock')
            ->whereIn('catename', ['GOLD', 'SILVER'])
            ->get()->unique('prid');
        $productInfo = $this->legacy->table('tbl_product')->get()->keyBy('prid');

        foreach ($rows as $r) {
            if (! $r->prid) {
                continue;
            }
            $info = $productInfo->get($r->prid);
            $cp = CatalogProduct::updateOrCreate(
                ['code' => $this->clip((string) $r->prid, 40)],
                [
                    'name' => ['en' => $this->clip($r->prname ?: ('Item ' . $r->prid), 255)],
                    'category_id' => $this->catMap[$r->cateid] ?? null,
                    'subcategory_id' => $this->subcatMap[$r->scateid] ?? null,
                    'material' => $this->materialOf($r->catename),
                    'default_purity' => $this->clip($r->purity ?: null, 12),
                    'making_charge_pct' => $this->num($r->mcharge),
                    'wastage_charge_pct' => $this->num($r->wcharge),
                    'hallmark_charge' => $this->num($r->acharge),
                    'gst_pct' => $this->num($r->gsti ?: 3),
                    'gm_margin' => $this->num($info->margin ?? 0),
                    'hsn_code' => $this->clip($info->hsncode ?? null, 20),
                    'is_active' => true,
                ],
            );
            $this->productMap[$r->prid] = $cp->id;
            \App\Models\StockTransferMargin::firstOrCreate(['catalog_product_id' => $cp->id]);
        }
    }

    protected function importStock(): void
    {
        // aggregate first so re-runs overwrite instead of double-counting
        $agg = [];
        $rows = $this->legacy->table('tbl_stock')
            ->select('branchid', 'prid', 'purity', DB::raw('SUM(weight) as qty'), DB::raw('MAX(purprice) as rate'))
            ->whereIn('catename', ['GOLD', 'SILVER'])
            ->where('weight', '>', 0)
            ->groupBy('branchid', 'prid', 'purity')
            ->get();

        foreach ($rows as $r) {
            $branchId = $this->branchMap[(int) $r->branchid] ?? null;
            $productId = $this->productMap[$r->prid] ?? null;
            if (! $branchId || ! $productId) {
                continue;
            }
            $key = $branchId . ':' . $productId;
            $agg[$key] ??= ['branch_id' => $branchId, 'catalog_product_id' => $productId,
                'quantity' => 0.0, 'purity' => null, 'last_rate' => 0.0];
            $agg[$key]['quantity'] += (float) $r->qty;
            $agg[$key]['purity'] = $this->clip($r->purity ?: $agg[$key]['purity'], 12);
            $agg[$key]['last_rate'] = max($agg[$key]['last_rate'], $this->num($r->rate));
        }

        foreach ($agg as $row) {
            DB::table('stock')->updateOrInsert(
                ['branch_id' => $row['branch_id'], 'catalog_product_id' => $row['catalog_product_id']],
                ['quantity' => $row['quantity'], 'purity' => $row['purity'],
                 'last_rate' => $row['last_rate'], 'updated_at' => now(), 'created_at' => now()],
            );
        }
        $this->note('stock', count($agg), 0);
    }

    // ----------------------------------------------------------------- members

    protected function importMembers(): void
    {
        $rankId = Rank::where('depth', 0)->value('id');
        $in = 0;

        $this->legacy->table('tbl_member')
            ->whereNotNull('userid')->where('userid', '!=', '')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use ($rankId, &$in) {
                $batch = [];
                foreach ($rows as $m) {
                    $code = $this->clip($m->userid, 30);
                    $this->legacyIdToCode[(int) $m->id] = $code;
                    $batch[$code] = [
                        'member_code' => $code,
                        'name' => $this->clip($m->memname ?: $m->userid, 200),
                        'phone' => $this->clip($m->mobileno ?: '0000000000', 20),
                        'email' => $this->clip($m->emailid ?: null, 150),
                        'dob' => $this->date($m->dob),
                        'father_name' => $this->clip($m->fhname ?: null, 200),
                        'address' => $this->clip($m->address ?: null, 255),
                        'city' => $this->clip($m->city ?: null, 120),
                        'pincode' => $this->clip($m->pincode ?: null, 12),
                        'pan' => $this->clip($m->panno ?: null, 15),
                        'aadhaar' => $this->clip($m->aadharno ?: null, 16),
                        'bank_name' => $this->clip($m->bankname ?: null, 150),
                        'bank_acno' => $this->clip($m->bankacno ?: null, 30),
                        'ifsc' => $this->clip($m->ifscno ?: null, 15),
                        'upi' => $this->clip($m->upiid ?: null, 80),
                        'nominee_name' => $this->clip($m->nomineename ?: null, 200),
                        'nominee_age' => is_numeric($m->nomineeage) ? (int) $m->nomineeage : null,
                        'nominee_relation' => $this->clip($m->nomineerel ?: null, 60),
                        'nominee_phone' => $this->clip($m->nomineephone ?: null, 20),
                        'joined_on' => $this->date($m->doj) ?? '2020-01-01',
                        'placement' => 'level',
                        'rank_id' => $rankId,
                        'bv' => $this->num($m->mybv),
                        'gbv' => $this->num($m->mygbv),
                        'unpure_bv' => $this->num($m->unpurebv ?? 0),
                        'unpure_gbv' => $this->num($m->unpuregbv ?? 0),
                        'downline_count' => (int) $m->lvldownlinecount,
                        'status' => (int) $m->status === 1 ? 'active' : 'inactive',
                        'created_at' => $this->date($m->doj) ?? now(),
                        'updated_at' => now(),
                    ];
                }
                $batch = array_values($batch);
                DB::table('members')->upsert($batch, ['member_code'], array_diff(array_keys($batch[0]), ['member_code', 'created_at']));
                $in += count($batch);
            });

        $this->memberMap = DB::table('members')->pluck('id', 'member_code')->all();
        $this->note('members', $in, 0);
    }

    protected function linkMemberNetwork(): void
    {
        $in = 0;
        $this->legacy->table('tbl_member')
            ->select('userid', 'uplineusername', 'refererusername')
            ->whereNotNull('userid')->where('userid', '!=', '')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$in) {
                foreach ($rows as $m) {
                    $id = $this->memberMap[$this->clip($m->userid, 30)] ?? null;
                    if (! $id) {
                        continue;
                    }
                    $updates = [];
                    if ($m->uplineusername && isset($this->memberMap[$m->uplineusername])) {
                        $updates['upline_id'] = $this->memberMap[$m->uplineusername];
                    }
                    if ($m->refererusername && isset($this->memberMap[$m->refererusername])) {
                        $updates['referrer_id'] = $this->memberMap[$m->refererusername];
                    }
                    if ($updates) {
                        DB::table('members')->where('id', $id)->update($updates);
                        $in++;
                    }
                }
            });
        $this->note('member links', $in, 0);
    }

    protected function importWallets(): void
    {
        $in = 0;
        $this->legacy->table('tbl_member')
            ->select('userid', 'walletamount', 'couponwallet', 'epinwallet', 'tearningval', 'twithdrawlval')
            ->whereNotNull('userid')->where('userid', '!=', '')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$in) {
                $batch = [];
                foreach ($rows as $m) {
                    $id = $this->memberMap[$this->clip($m->userid, 30)] ?? null;
                    if (! $id) {
                        continue;
                    }
                    $batch[$id] = [
                        'member_id' => $id,
                        'cash_balance' => $this->num($m->walletamount),
                        'coupon_balance' => $this->num($m->couponwallet),
                        'epin_balance' => $this->num($m->epinwallet),
                        'earning_total' => $this->num($m->tearningval),
                        'withdrawn_total' => $this->num($m->twithdrawlval),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                if ($batch) {
                    $batch = array_values($batch);
                    DB::table('member_wallets')->upsert($batch, ['member_id'], array_diff(array_keys($batch[0]), ['member_id', 'created_at']));
                    $in += count($batch);
                }
            });
        $this->note('wallets', $in, 0);
    }

    protected function importCustomers(): void
    {
        // customers.phone is NOT NULL + UNIQUE: skip blank phones, dedupe repeats
        // (first occurrence wins), and chunk the insert so one bad row can't void all.
        [$in, $skip] = [0, 0];
        $batch = [];
        $seen = [];
        foreach ($this->legacy->table('tbl_customer')->orderBy('cusid')->get() as $c) {
            $phone = $this->clip($c->phone ?: null, 20);
            if ($phone === null || isset($seen[$phone])) {
                $skip++;
                continue;
            }
            $seen[$phone] = true;
            $batch[] = [
                'name' => $this->clip($c->name ?: 'Customer ' . $c->cusid, 255),
                'phone' => $phone,
                'email' => $this->clip($c->email ?: null, 255),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($batch, 1000) as $chunk) {
            DB::table('customers')->insert($chunk);
            $in += count($chunk);
        }
        $this->note('customers', $in, $skip);
    }

    // ----------------------------------------------------------- transactions

    protected function importBonds(): void
    {
        [$in, $skip] = [0, 0];
        $this->legacy->table('tbl_bond')->orderBy('bondid')
            ->chunk(1000, function ($rows) use (&$in, &$skip) {
                $batch = [];
                foreach ($rows as $b) {
                    $memberId = $this->memberMap[$this->clip($b->memuserid, 30)] ?? null;
                    $planId = $this->planMap[(int) $b->planid] ?? null;
                    if (! $memberId || ! $planId) {
                        $skip++;
                        continue;
                    }
                    $date = $this->date($b->bdate) ?? '2020-01-01';
                    $batch[] = [
                        'id' => (int) $b->bondid,
                        'member_id' => $memberId,
                        'plan_id' => $planId,
                        'branch_id' => $this->branchMap[(int) $b->branchid] ?? null,
                        // NOT productMap: bonds.product_id is an enforced FK to the
                        // storefront `products` table; productMap holds CATALOG ids.
                        // Legacy bprid has no products-table counterpart — leave null.
                        'product_id' => null,
                        'bond_date' => $date,
                        'value' => $this->num($b->bvalue),
                        'invoice_no' => $this->clip($b->invoiceno ?: null, 40),
                        'lvlcom' => json_encode(array_map(fn ($k) => $this->num($b->$k),
                            ['lvlcomone', 'lvlcomtwo', 'lvlcomthree', 'lvlcomfour', 'lvlcomfive'])),
                        'cbc_value' => $this->num($b->cbcvalue),
                        'cbc_count' => max(0, (int) $b->cbccount),
                        'cbc_issued' => max(0, (int) $b->cbcissue),
                        'lvlcom_count' => max(0, (int) $b->lvlcomcount),
                        'lvlcom_issued' => max(0, (int) $b->lvlcomissue),
                        'epin_value' => $this->num($b->epinvalue),
                        'return_date' => $this->date($b->returndate),
                        'mou_id' => $this->mouMap[$b->mouid] ?? null,
                        'status' => (int) $b->bondstatus === 1 ? 'active' : 'closed',
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('bonds')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('bonds', $in, $skip);
    }

    protected function importRdEntries(): void
    {
        [$in, $skip] = [0, 0];
        $bondIds = DB::table('bonds')->pluck('id')->flip()->all();
        $this->legacy->table('tbl_rdentry')->orderBy('trid')
            ->chunk(1000, function ($rows) use (&$in, &$skip, $bondIds) {
                $batch = [];
                foreach ($rows as $r) {
                    $memberId = $this->memberMap[$this->clip($r->memuuserid, 30)] ?? null;
                    if (! $memberId || ! isset($bondIds[(int) $r->bondid])) {
                        $skip++;
                        continue;
                    }
                    $date = $this->date($r->trdate) ?? '2020-01-01';
                    $batch[] = [
                        'bond_id' => (int) $r->bondid,
                        'member_id' => $memberId,
                        'paid_on' => $date,
                        'value' => $this->num($r->bvalue),
                        'due_count' => max(0, (int) $r->duecount),
                        'branch_id' => $this->branchMap[(int) $r->branchid] ?? null,
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('rd_entries')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('rd_entries', $in, $skip);
    }

    protected function importCbcEntries(): void
    {
        [$in, $skip] = [0, 0];
        $seen = [];
        $bondIds = DB::table('bonds')->pluck('id')->flip()->all();
        $this->legacy->table('tbl_cbc')->orderBy('cbcid')
            ->chunk(1000, function ($rows) use (&$in, &$skip, &$seen, $bondIds) {
                $batch = [];
                foreach ($rows as $c) {
                    $memberId = $this->memberMap[$this->clip($c->memberusername, 30)] ?? null;
                    $bondId = (int) $c->bondid;
                    if (! $memberId || ! isset($bondIds[$bondId])) {
                        $skip++;
                        continue;
                    }
                    $date = $this->date($c->cbcdate) ?? '2020-01-01';
                    $code = $this->clip($c->cbcode ?: null, 16);
                    if ($code === null || isset($seen[$code])) {
                        $code = 'C' . $c->cbcid; // cbcode is unique in target; dedupe via legacy pk
                    }
                    $seen[$code] = true;
                    $batch[] = [
                        'bond_id' => $bondId,
                        'member_id' => $memberId,
                        'cbc_date' => $date,
                        'code' => $code,
                        'worth' => $this->num($c->cbworth),
                        'status' => strtoupper((string) $c->cbcavailabilty) === 'PASSED' ? 'paid' : 'pending',
                        'used_for' => $this->clip($c->usedformementry ?: null, 60),
                        'paid_on' => strtoupper((string) $c->cbcavailabilty) === 'PASSED' ? $date : null,
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('cbc_entries')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('cbc_entries', $in, $skip);
    }

    protected function importIcCommissions(): void
    {
        [$in, $skip] = [0, 0];
        $bondIds = DB::table('bonds')->pluck('id')->flip()->all();
        $this->legacy->table('tbl_iccom')->orderBy('ictran')
            ->chunk(2000, function ($rows) use (&$in, &$skip, $bondIds) {
                $batch = [];
                foreach ($rows as $c) {
                    $memberId = $this->memberMap[$this->clip($c->comusername, 30)] ?? null;
                    if (! $memberId) {
                        $skip++;
                        continue;
                    }
                    $status = match (strtoupper((string) $c->status)) {
                        'PAID' => 'paid', 'PASSED' => 'approved', default => 'pending',
                    };
                    $amount = $this->num($c->comamount);
                    $date = $this->date($c->billdate) ?? '2020-01-01';
                    $bondId = (int) $c->bondno;
                    $batch[] = [
                        'type' => 'IC',
                        'member_id' => $memberId,
                        'from_member_id' => $this->memberMap[$this->clip($c->fromid, 30)] ?? null,
                        'bond_id' => isset($bondIds[$bondId]) ? $bondId : null,
                        'invoice_no' => $this->clip($c->billno ?: null, 40),
                        'level' => null,
                        'amount' => $amount,
                        'status' => $status,
                        'pay_via' => $this->payVia($c->payvia),
                        'earned_on' => $date,
                        'paid_on' => $status === 'paid' ? $date : null,
                        'branch_id' => $this->branchMap[(int) $c->billbybranch] ?? null,
                        'tds' => 0, 'service_charge' => 0, 'net_amount' => $amount,
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('commission_ledger')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('commission_ledger IC', $in, $skip);
    }

    protected function importGapCommissions(): void
    {
        [$in, $skip] = [0, 0];
        $this->legacy->table('tbl_memberearning_level')->orderBy('earnid')
            ->chunk(2000, function ($rows) use (&$in, &$skip) {
                $batch = [];
                foreach ($rows as $c) {
                    $memberId = $this->memberMap[$this->clip($c->memberuserid, 30)] ?? null;
                    if (! $memberId) {
                        $skip++;
                        continue;
                    }
                    $status = strtoupper((string) $c->status) === 'PAID' ? 'paid' : 'pending';
                    $amount = $this->num($c->earnamount);
                    $date = $this->date($c->doe) ?? '2020-01-01';
                    $batch[] = [
                        'type' => 'GAP',
                        'member_id' => $memberId,
                        'from_member_id' => null,
                        'bond_id' => null,
                        'invoice_no' => $this->clip($c->referance ?: null, 40),
                        'level' => null,
                        'amount' => $amount,
                        'status' => $status,
                        'pay_via' => null,
                        'earned_on' => $date,
                        'paid_on' => $status === 'paid' ? $date : null,
                        'branch_id' => null,
                        'tds' => 0, 'service_charge' => 0, 'net_amount' => $amount,
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('commission_ledger')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('commission_ledger GAP', $in, $skip);
    }

    protected function importResellerCommissions(): void
    {
        [$in, $skip] = [0, 0];
        $usersByCode = User::whereNotNull('member_code')->pluck('id', 'member_code')->all();
        $this->legacy->table('tbl_reseller_com')->orderBy('transid')
            ->chunk(2000, function ($rows) use (&$in, $usersByCode) {
                $batch = [];
                foreach ($rows as $c) {
                    $date = $this->date($c->billdate) ?? '2020-01-01';
                    $status = match (strtoupper((string) $c->status)) {
                        'PAID' => 'paid', 'PASSED' => 'passed', default => 'pending',
                    };
                    $batch[] = [
                        'bill_date' => $date,
                        'invoice_no' => $this->clip($c->billno ?: null, 40),
                        'com_type_id' => max(1, (int) $c->comtypeid),
                        'user_id' => $usersByCode[$this->clip($c->userid, 30)] ?? null,
                        'branch_id' => $this->branchMap[(int) $c->brid] ?? null,
                        'mapped_uid' => $this->clip($c->mappeduid ?: null, 40),
                        'com_value' => $this->num($c->comvalue),
                        'reference_member_id' => $this->memberMap[$this->clip($c->userid, 30)] ?? null,
                        'status' => $status,
                        'paid_on' => $status === 'paid' ? $date : null,
                        'tds' => 0, 'service_charge' => 0,
                        'net_amount' => $this->num($c->comvalue),
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('reseller_commissions')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('reseller_commissions', $in, $skip);
    }

    protected function importSalesInvoices(): void
    {
        [$in, $skip] = [0, 0];
        $this->legacy->table('tbl_salesinvoice')->orderBy('estid')
            ->chunk(1000, function ($rows) use (&$in) {
                $batch = [];
                foreach ($rows as $s) {
                    $date = $this->date($s->estdate) ?? '2020-01-01';
                    $cusKey = $this->clip($s->cusid, 30);
                    $memberId = $this->memberMap[$cusKey]
                        ?? $this->memberMap[$this->legacyIdToCode[(int) $s->cusid] ?? ''] ?? null;
                    $batch[] = [
                        'id' => (int) $s->estid,
                        'invoice_no' => (string) $s->estid,
                        'date' => $date,
                        'customer_member_id' => $memberId,
                        'customer_name' => $this->clip($s->cusname ?: null, 200),
                        'branch_id' => $this->branchMap[(int) $s->branchid] ?? null,
                        'cross_total' => $this->num($s->crosstotal),
                        'discount' => $this->num($s->finaldiscount ?: $s->disc),
                        'net_total' => $this->num($s->netttotal),
                        'sgst' => $this->num($s->sgst),
                        'cgst' => $this->num($s->cgst),
                        'grand_total' => $this->num($s->grandtotal),
                        'received' => $this->num($s->recamount),
                        'payment_type' => match (strtolower((string) $s->paymenttype)) {
                            'cashmode', 'cash' => 'cash',
                            'cheque' => 'cheque',
                            default => 'online',
                        },
                        'payment_reference' => $this->clip($s->paymentremarks ?: null, 120),
                        'gold_rate' => $this->num($s->goldrate),
                        'silver_rate' => $this->num($s->silverrate),
                        'remarks' => 'SALES',
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('sales_invoices')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('sales_invoices', $in, $skip);
    }

    protected function importSaleLines(): void
    {
        [$in, $skip] = [0, 0];
        $invoiceIds = DB::table('sales_invoices')->pluck('id')->flip()->all();
        $this->legacy->table('tbl_sales_cart')->orderBy('cartid')
            ->chunk(1000, function ($rows) use (&$in, &$skip, $invoiceIds) {
                $batch = [];
                foreach ($rows as $c) {
                    $invId = (int) $c->invno;
                    if (! isset($invoiceIds[$invId])) {
                        $skip++;
                        continue;
                    }
                    $batch[] = [
                        'invoice_id' => $invId,
                        'catalog_product_id' => $this->productMap[$c->prid] ?? null,
                        'material' => strtolower($this->clip($c->catename ?: 'gold', 20)),
                        'description' => $this->clip($c->prname ?: null, 200),
                        'qty' => $this->num($c->weight),
                        'rate' => $this->num($c->matprice ?: $c->purprice),
                        'making' => $this->num($c->cg_mc),
                        'wastage' => $this->num($c->cg_wc),
                        'line_total' => $this->num($c->cg_aftercharge ?: $c->aftercharges),
                    ];
                }
                if ($batch) {
                    DB::table('sale_lines')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('sale_lines', $in, $skip);
    }

    protected function importDigiOrders(): void
    {
        [$in, $skip] = [0, 0];
        $seen = [];
        $this->legacy->table('tbl_order_digiunique')->orderBy('digiorderid')
            ->chunk(2000, function ($rows) use (&$in, &$seen) {
                $batch = [];
                foreach ($rows as $o) {
                    $memberId = $this->memberMap[$this->clip($o->cus_type, 30)]
                        ?? $this->memberMap[$this->clip($o->cus_id, 30)] ?? null;
                    $date = $this->date($o->transdate) ?? '2020-01-01';
                    $wt = $this->num($o->gold_wt);
                    $rate = $this->num($o->rateondate);
                    $ref = $this->clip($o->orderid ?: null, 40);
                    if ($ref === null || isset($seen[$ref])) {
                        $ref = 'DG' . $o->digiorderid; // ref_no is unique in target; dedupe via legacy pk
                    }
                    $seen[$ref] = true;
                    $batch[] = [
                        'ref_no' => $ref,
                        'member_id' => $memberId,
                        'source' => in_array($o->mode, ['IC', 'SP', 'LEVEL', 'BM'], true) ? $o->mode : 'IC',
                        'gold_wt' => $wt,
                        'value' => round($wt * $rate, 2),
                        'rate_on_date' => $rate,
                        'branch_id' => null,
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('digi_orders')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('digi_orders', $in, $skip);
    }

    protected function importDigiQueue(): void
    {
        [$in, $skip] = [0, 0];
        // digi_queue.qr_code is NOT NULL + UNIQUE — same guard as importDigiOrders:
        // blank/duplicate codes fall back to a synthetic key from the legacy PK.
        $seen = [];
        $this->legacy->table('tbl_digi_queue')->orderBy('trid')
            ->chunk(2000, function ($rows) use (&$in, &$seen) {
                $batch = [];
                foreach ($rows as $q) {
                    $date = $this->date($q->dot) ?? '2020-01-01';
                    $mode = strtolower((string) $q->qrmode);
                    $code = $this->clip($q->qrcode ?: null, 40);
                    if ($code === null || isset($seen[$code])) {
                        $code = 'DQ' . $q->trid;
                    }
                    $seen[$code] = true;
                    $batch[] = [
                        'member_id' => $this->memberMap[$this->clip($q->userid, 30)] ?? null,
                        'qr_code' => $code,
                        'qr_mode' => in_array($mode, ['cash', 'gold', 'silver'], true) ? $mode : 'cash',
                        'gram_worth' => $this->num($q->gm_worth),
                        'cash_worth' => $this->num($q->cash_worth),
                        'reference' => $this->clip($q->refferance ?: null, 40),
                        'route' => $this->clip($q->route ?: null, 40),
                        'delivery_status' => strtoupper((string) $q->deliverystatus) === 'REDEEMED' ? 'redeemed' : 'pending',
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
        $this->note('digi_queue', $in, $skip);
    }

    protected function importDigiWithdrawals(): void
    {
        [$in, $skip] = [0, 0];
        $this->legacy->table('tbl_digiwithdrawal')->orderBy('wdid')
            ->chunk(2000, function ($rows) use (&$in, &$skip) {
                $batch = [];
                foreach ($rows as $w) {
                    $memberId = $this->memberMap[$this->clip($w->custype, 30)]
                        ?? $this->memberMap[$this->clip($w->cusid, 30)] ?? null;
                    if (! $memberId) {
                        $skip++;
                        continue;
                    }
                    $date = $this->date($w->wddate) ?? '2020-01-01';
                    $batch[] = [
                        'member_id' => $memberId,
                        'gold_wt' => $this->num($w->wdgoldgm),
                        'worth' => $this->num($w->wdgoldworth),
                        'status' => strtoupper((string) $w->status) === 'PENDING' ? 'pending' : 'approved',
                        'created_at' => $date, 'updated_at' => $date,
                    ];
                }
                if ($batch) {
                    DB::table('digi_withdrawals')->insert($batch);
                    $in += count($batch);
                }
            });
        $this->note('digi_withdrawals', $in, $skip);
    }

    protected function importEpinLogs(): void
    {
        [$in, $skip] = [0, 0];
        foreach ($this->legacy->table('tbl_epinlog')->get() as $l) {
            $memberId = $this->memberMap[$this->clip($l->memusername, 30)] ?? null;
            if (! $memberId) {
                $skip++;
                continue;
            }
            $date = $this->date($l->transdate) ?? '2020-01-01';
            DB::table('epin_logs')->insert([
                'member_id' => $memberId,
                'qty' => max(0, (int) $l->qty),
                'unit_price' => $this->num($l->price),
                'net_total' => $this->num($l->nettotal),
                'remarks' => $this->clip($l->remarks ?: null, 120),
                'created_at' => $date, 'updated_at' => $date,
            ]);
            $in++;
        }
        $this->note('epin_logs', $in, $skip);
    }

    // ----------------------------------------------------------------- helpers

    protected function note(string $step, int $in, int $skip): void
    {
        $this->stats[$step] = ['in' => $in, 'skip' => $skip];
        $this->line(sprintf('  %-24s %6d imported, %d skipped', $step, $in, $skip));
    }

    protected function payVia(?string $v): ?string
    {
        $v = strtoupper(trim((string) $v));
        return match (true) {
            $v === 'DIGI TRANSFER' => 'digi_transfer',
            str_starts_with($v, 'ONLINE') => 'online',
            $v === 'CASH' => 'cash',
            default => null,
        };
    }

    protected function num($v): float
    {
        $v = str_replace([',', ' '], '', (string) $v);

        return is_numeric($v) ? (float) $v : 0.0;
    }

    protected function numbers($csv): array
    {
        return collect(preg_split('/,+/', (string) $csv))
            ->map(fn ($x) => trim($x))
            ->filter(fn ($x) => is_numeric($x))
            ->values()->all();
    }

    protected function clip($val, int $len): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }

        return mb_substr((string) $val, 0, $len);
    }

    protected function materialOf(?string $catename): string
    {
        return match (strtoupper((string) $catename)) {
            'GOLD' => 'gold',
            'SILVER' => 'silver',
            default => 'vessel',
        };
    }

    protected function date($d): ?string
    {
        if (! $d || str_starts_with((string) $d, '0000-00-00') || ! strtotime((string) $d)) {
            return null;
        }

        return date('Y-m-d', strtotime((string) $d));
    }
}
