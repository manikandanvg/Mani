<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * User 2026-11-02: in legacy every area dealer / reseller / wholesaler / taluk dealer is a
 * tbl_branches row named "LORD JEWELLER" in "SIVAKASI" — the same name and city as Head
 * Office; only brid, phone, in-charge and address differ. The roll-out importer identified a
 * branch by name + city and so folded 1,037 of them INTO Head Office: their logins, members,
 * stock, margins, contracts and bills all landed on branch #1.
 *
 * This command gives every legacy branch its own row again, keyed on the permanent
 * `branches.legacy_brid`, and moves what belongs to it OUT of the branch it was pooled into:
 *
 *   - the branch itself (name, city, address, phone, in-charge, GST, level from its login's
 *     designation, supplier from the login's upline, GPS)
 *   - its margins / digi cash (subtracted from the pool branch)
 *   - its logins (ci_users.brid → users via member_code = mapid, or e-mail)
 *   - its members (tbl_member.entryby → those logins)
 *   - its stock: legacy ACTIVE tbl_stock rows per product, taken from the pool branch
 *     (stock_movements "transfer" on both sides, ref_type legacy_split, ref_id = brid)
 *   - its contracts (bonds + member_contracts LEG-<bondid> + redeemable QRs of those bonds),
 *     RD entries, sales invoices, dealer (reseller) commissions, IC commission rows
 *
 * Only rows that currently sit on the pool branch are moved — anything already elsewhere is
 * left alone, so the command is idempotent and safe to re-run. Branches whose name + city was
 * unique are simply stamped with their legacy_brid. Everything runs in ONE transaction:
 * --dry-run executes the whole thing and rolls it back, printing the same report.
 *
 *   php artisan legacy:split-merged-branches --dry-run
 *   php artisan legacy:split-merged-branches
 */
class LegacySplitMergedBranches extends Command
{
    protected $signature = 'legacy:split-merged-branches
        {--dry-run : run everything inside a transaction and roll it back}
        {--since=2026-11-02 : go-live date — bills / renewals made in the new system from this day by a moved login are carried to its branch}';

    protected $description = 'Give every legacy branch (tbl_branches.brid) its own branch again and move its logins, members, stock, margins, contracts and bills out of the branch they were merged into';

    protected Connection $legacy;

    /** @var array<string> */
    protected array $warnings = [];

    /** @var array<string,int> */
    protected array $counts = [];

    /** @var array<int,array{name:string,qty:float,short:float}> product id → moved qty */
    protected array $moved = [];

    /** @var array<int,int> catalog product id by legacy prid (= catalog code) */
    protected array $products = [];

    /** @var array<int,array<int,int>> branch id → [brid, ...] moved from it */
    protected array $fromPool = [];

    /** @var array<int,array<string,float>> pool branch id → margins claimed by the branches split out of it */
    protected array $marginsMoved = [];

    protected const MARGIN_COLS = ['bill_margin', 'gold_gm_margin', 'silver_gm_margin', 'stock_trans_margin', 'digi_cash_balance'];

    /** @var array<int,\Illuminate\Support\Collection> brid → ci_users rows */
    protected array $loginsByBrid = [];

    /** @var array<string,int> ci_users.mapid → brid */
    protected array $bridByMapid = [];

    /** @var array<int,int> brid → new/own branch id */
    protected array $byBrid = [];

    // legacy tables read ONCE and grouped by brid — per-branch queries on tbl_rdentry / tbl_iccom
    // (no index on the branch column) took minutes per 100 branches
    /** @var array<int,array<int>> brid → bond ids */
    protected array $bondsByBrid = [];

    /** @var array<int,array<int,array<string,bool>>> brid → bond id → paid dates */
    protected array $rdByBrid = [];

    /** @var array<int,array<int>> brid → sales invoice ids (estid) */
    protected array $estByBrid = [];

    /** @var array<int,array<string,array{billno:string,mapped:?string,value:float,login:int,n:int}>> brid → dealer-commission rows (grouped) */
    protected array $resComByBrid = [];

    /** @var array<int,array<int,array<string,bool>>> brid → bond id → IC bill numbers */
    protected array $icByBrid = [];

    /** @var array<int,array<string,array<int>>> pool branch id → "bill|mapped|value" → reseller_commissions ids still on the pool */
    protected array $poolResCom = [];

    /** @var array<int,array<int,array{qty:float,rate:float}>> brid → prid → ACTIVE stock */
    protected array $stockByBrid = [];

    /** @var array<int,array<string>> ci_users.id → member codes entered by that login */
    protected array $memberCodesByLogin = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->legacy = DB::connection('legacy');

        DB::beginTransaction();
        try {
            // per-row activity logs (1,000+ branches, 2,000+ stock movements) would drown the log — one summary line instead
            ActivityLogger::silenced(fn () => $this->perform());
            if (($this->counts['branches created'] ?? 0) > 0) {
                ActivityLogger::log('branch', 'legacy_split', sprintf('%d legacy branches given their own branch again (split out of the branch they were merged into); %d logins, %d members moved',
                    $this->counts['branches created'], $this->counts['logins moved'] ?? 0, $this->counts['members moved'] ?? 0));
            }
            if ($dry) {
                DB::rollBack();
                $this->warn('[DRY RUN] everything above was rolled back — nothing changed.');
            } else {
                DB::commit();
                $this->info('Committed.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return self::SUCCESS;
    }

    protected function perform(): void
    {
        $hq = Branch::where('level', 'hq')->orderBy('id')->first() ?? Branch::orderBy('id')->firstOrFail();
        $this->products = CatalogProduct::query()->get(['id', 'code'])->mapWithKeys(fn ($p) => [(string) $p->code => (int) $p->id])->all();
        $this->byBrid = Branch::withTrashed()->whereNotNull('legacy_brid')->pluck('id', 'legacy_brid')->map(fn ($v) => (int) $v)->all();

        foreach ($this->legacy->table('ci_users')->orderBy('id')->get() as $u) {
            $this->loginsByBrid[(int) $u->brid][] = $u;
            if ($u->mapid !== '' && $u->brid !== '') {
                $this->bridByMapid[(string) $u->mapid] ??= (int) $u->brid;
            }
        }
        $this->loginsByBrid = array_map(fn ($rows) => collect($rows), $this->loginsByBrid);
        $this->prefetchLegacy();

        $rows = $this->legacy->table('tbl_branches')->orderBy('brid')->get();
        $hqRow = $rows->firstWhere('brid', 1);
        if (! isset($this->byBrid[1])) {
            Branch::withoutEvents(fn () => $hq->forceFill(['legacy_brid' => 1])->save());
            $this->byBrid[1] = (int) $hq->id;
        }
        $hqKey = $hqRow ? $this->key($this->plain($hqRow->storename), $this->plain($hqRow->city)) : $this->key($hq->name, $hq->city);
        $hq->refresh();

        // existing branches by name|city — the key the importer merged on
        $existingByKey = [];
        foreach (Branch::withTrashed()->orderBy('id')->get() as $b) {
            $existingByKey[$this->key($b->name, $b->city)] ??= $b;
        }
        $existingByKey[$hqKey] = $hq;

        $groups = [];
        foreach ($rows as $b) {
            if ((int) $b->brid === 1) {
                continue;
            }
            $groups[$this->key($this->plain($b->storename), $this->plain($b->city))][] = $b;
        }

        $this->line(sprintf('Legacy branches: %d · already keyed by legacy_brid: %d · Head Office = branch #%d', $rows->count(), count($this->byBrid), $hq->id));
        $created = [];
        foreach ($groups as $key => $list) {
            $pool = $existingByKey[$key] ?? null;
            foreach ($list as $i => $b) {
                $brid = (int) $b->brid;
                if (isset($this->byBrid[$brid])) {
                    continue;                                            // already its own branch
                }
                if ($pool && $pool->legacy_brid === null && $i === 0) {
                    // the branch the importer created from this (first) legacy row — stamp it
                    Branch::withoutEvents(fn () => $pool->forceFill(['legacy_brid' => $brid])->save());
                    $this->byBrid[$brid] = (int) $pool->id;
                    $this->bump('branches stamped');
                    continue;
                }
                $created[$brid] = $this->split($pool, $b);
            }
        }

        // second pass: supplier = the upline login's branch (which may itself have just been created)
        foreach ($created as $brid => $branch) {
            $upline = ($this->loginsByBrid[$brid] ?? collect())->pluck('uplineid')->filter(fn ($x) => $x !== '' && $x !== null)->first();
            $sourceId = $upline !== null ? ($this->byBrid[$this->bridByMapid[(string) $upline] ?? 0] ?? null) : null;
            if ($sourceId && $sourceId !== (int) $branch->id) {
                DB::table('branches')->where('id', $branch->id)->update(['source_branch_id' => $sourceId]);
            }
        }

        $this->settlePoolMargins();
        $this->seedPendingBillMargins((int) $hq->id);
        $this->report();
    }

    /**
     * User 2026-11-02: the legacy accrued billing margin (ci_users.billmargin, the UNPAID balance
     * the dealer saw in legacy) is never pushed into a wallet. One pending_bill_margins row per
     * legacy branch waits for Head Office on Commissions → Pending Billing Margin. HQ's own
     * figure is a company figure, not a dealer margin. Idempotent: a branch that has its row is skipped.
     */
    protected function seedPendingBillMargins(int $hqId): void
    {
        $have = DB::table('pending_bill_margins')->where('source', 'legacy')->pluck('branch_id')->flip()->all();
        foreach ($this->byBrid as $brid => $branchId) {
            if ($branchId === $hqId || isset($have[$branchId])) {
                continue;
            }
            $amount = 0.0;
            foreach ($this->loginsByBrid[(int) $brid] ?? collect() as $u) {
                $amount += max(0.0, $this->num($u->billmargin));
            }
            if ($amount <= 0) {
                continue;
            }
            DB::table('pending_bill_margins')->insert([
                'branch_id' => $branchId, 'source' => 'legacy', 'amount' => round($amount, 2), 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->bump('pending billing margins seeded');
            $this->counts['pending billing margin ₹'] = (int) round(($this->counts['pending billing margin ₹'] ?? 0) + $amount);
        }
    }

    /**
     * The pool branch keeps the larger of (what it has now − what the split-out branches claim) and
     * its OWN legacy figure (the logins on its own brid). HQ was given the SUM of every merged login's
     * margin at import; its own share is what its brid-1 logins carry.
     */
    protected function settlePoolMargins(): void
    {
        foreach ($this->marginsMoved as $poolId => $moved) {
            $pool = Branch::withTrashed()->find($poolId);
            $own = array_fill_keys(self::MARGIN_COLS, 0.0);
            foreach ($this->loginsByBrid[(int) $pool->legacy_brid] ?? collect() as $u) {
                $own['bill_margin'] += max(0.0, $this->num($u->billmargin));
                $own['gold_gm_margin'] += $this->num($u->goldgmmargin);
                $own['silver_gm_margin'] += $this->num($u->silvergmmargin);
                $own['stock_trans_margin'] += $this->num($u->stocktransmargin);
                $own['digi_cash_balance'] += $this->num($u->digicash);
            }
            $upd = [];
            foreach (self::MARGIN_COLS as $col) {
                $have = (float) $pool->{$col};
                $claim = $moved[$col] ?? 0.0;
                if ($claim <= 0) {
                    continue;
                }
                $upd[$col] = round(max($have - $claim, $own[$col]), 3);
                $this->line(sprintf('  pool #%d %-20s had %16s · split-out branches take %16s · own legacy figure %14s → %16s',
                    $poolId, $col, number_format($have, 2), number_format($claim, 2), number_format($own[$col], 2), number_format($upd[$col], 2)));
            }
            if ($upd) {
                DB::table('branches')->where('id', $poolId)->update($upd);
            }
        }
    }

    protected function prefetchLegacy(): void
    {
        $L = $this->legacy;
        $L->table('tbl_bond')->select('bondid', 'branchid')->orderBy('bondid')->chunk(5000, function ($rows) {
            foreach ($rows as $r) {
                $this->bondsByBrid[(int) $r->branchid][] = (int) $r->bondid;
            }
        });
        $L->table('tbl_rdentry')->select('bondid', 'trdate', 'branchid')->orderBy('trid')->chunk(5000, function ($rows) {
            foreach ($rows as $r) {
                if ($d = $this->date($r->trdate)) {
                    $this->rdByBrid[(int) $r->branchid][(int) $r->bondid][$d] = true;
                }
            }
        });
        $L->table('tbl_salesinvoice')->select('estid', 'branchid')->orderBy('estid')->chunk(5000, function ($rows) {
            foreach ($rows as $r) {
                $this->estByBrid[(int) $r->branchid][] = (int) $r->estid;
            }
        });
        // tbl_reseller_com.userid is the ci_users id (NOT a member code — the importer left user_id
        // null); rows are matched on bill number + mapped uid + value and get their user filled in
        $L->table('tbl_reseller_com')->select('brid', 'billno', 'mappeduid', 'comvalue', 'userid')->whereNotNull('billno')->where('billno', '!=', '')->orderBy('transid')->chunk(5000, function ($rows) {
            foreach ($rows as $r) {
                $mapped = $this->clip($r->mappeduid ?: null, 40);
                $k = $r->billno . '|' . $mapped . '|' . $this->num($r->comvalue) . '|' . (int) $r->userid;
                $g = &$this->resComByBrid[(int) $r->brid][$k];
                $g ??= ['billno' => $this->clip($r->billno, 40), 'mapped' => $mapped, 'value' => $this->num($r->comvalue), 'login' => (int) $r->userid, 'n' => 0];
                $g['n']++;
                unset($g);
            }
        });
        $L->table('tbl_iccom')->select('bondno', 'billno', 'billbybranch')->orderBy('ictran')->chunk(5000, function ($rows) {
            foreach ($rows as $r) {
                $this->icByBrid[(int) $r->billbybranch][(int) $r->bondno][(string) $r->billno] = true;
            }
        });
        foreach ($L->table('tbl_stock')->whereRaw('UPPER(status) = ?', ['ACTIVE'])
            ->select('branchid', 'prid', DB::raw('SUM(weight) as qty'), DB::raw('MAX(purprice) as rate'))->groupBy('branchid', 'prid')->get() as $s) {
            $this->stockByBrid[(int) $s->branchid][(int) $s->prid] = ['qty' => (float) $s->qty, 'rate' => (float) $s->rate];
        }
        $L->table('tbl_member')->select('userid', 'entryby')->whereNotNull('userid')->where('userid', '!=', '')->orderBy('id')->chunk(5000, function ($rows) {
            foreach ($rows as $r) {
                if ($code = $this->clip($r->userid, 30)) {
                    $this->memberCodesByLogin[(int) $r->entryby][] = $code;
                }
            }
        });
    }

    /** Create the branch for one legacy row and move its things out of $pool (the branch it was merged into). */
    protected function split(?Branch $pool, object $b): Branch
    {
        $brid = (int) $b->brid;
        $logins = $this->loginsByBrid[$brid] ?? collect();
        $level = $logins->map(fn ($u) => Branch::levelFromDesignation($u->desiname))->filter()->first() ?? 'area_dealer';
        $m = ['bill_margin' => 0.0, 'gold_gm_margin' => 0.0, 'silver_gm_margin' => 0.0, 'stock_trans_margin' => 0.0, 'digi_cash_balance' => 0.0];
        $lat = $lng = null;
        foreach ($logins as $u) {
            $m['bill_margin'] += max(0.0, $this->num($u->billmargin));   // negative legacy margin = 0 (board 2026-11-01)
            $m['gold_gm_margin'] += $this->num($u->goldgmmargin);
            $m['silver_gm_margin'] += $this->num($u->silvergmmargin);
            $m['stock_trans_margin'] += $this->num($u->stocktransmargin);
            $m['digi_cash_balance'] += $this->num($u->digicash);
            if ($lat === null && is_numeric($u->latitude) && (float) $u->latitude != 0.0) {
                [$lat, $lng] = [(float) $u->latitude, (float) $u->longitude];
            }
        }
        $incharge = trim((string) $b->brincharge);
        $branch = Branch::withoutEvents(fn () => Branch::create([
            'legacy_brid' => $brid,
            'name' => $this->clip(trim($this->plain($b->storename)) ?: ('Branch ' . $brid), 200),
            'kind' => Branch::KIND_DEALER,
            'level' => $level,
            'address' => $this->clip($this->plain($b->address), 255),
            'city' => $this->clip(trim($this->plain($b->city)) ?: null, 120),
            'pincode' => $this->clip(preg_match('/\d{6}/', (string) $b->pincode, $mm) ? $mm[0] : null, 12),
            'country' => 'IN',
            'phone' => $this->clip($b->phoneno, 20),
            'incharge' => $this->clip($incharge === '-' ? null : $incharge, 200),
            'gst_no' => $this->clip($b->gstno, 25),
            'is_active' => (int) $b->status === 1,
            'bill_margin' => round($m['bill_margin'], 3), 'gold_gm_margin' => round($m['gold_gm_margin'], 2),
            'silver_gm_margin' => round($m['silver_gm_margin'], 2), 'stock_trans_margin' => round($m['stock_trans_margin'], 3),
            'digi_cash_balance' => round($m['digi_cash_balance'], 2),
            'latitude' => $lat, 'longitude' => $lng,
            'currency_code' => 'INR',
        ]));
        $this->byBrid[$brid] = (int) $branch->id;
        $this->bump('branches created');
        if (! $pool) {
            return $branch;                                              // never imported — nothing to move
        }
        $this->fromPool[(int) $pool->id][] = $brid;
        $poolId = (int) $pool->id;
        $newId = (int) $branch->id;

        // margins / digi cash: the dealer gets its legacy figure; the pool is settled once at the end
        // (settlePoolMargins) — legacy keeps accruing billing margin after the closing dump, so the
        // per-dealer sum can exceed what the pool was given at import.
        foreach ($m as $col => $amt) {
            $this->marginsMoved[$poolId][$col] = ($this->marginsMoved[$poolId][$col] ?? 0.0) + $amt;
        }

        // logins
        $loginIds = [];
        $userByLogin = [];   // ci_users.id → users.id
        $movedUsers = [];    // users.id moved off the pool in this run
        foreach ($logins as $u) {
            $loginIds[] = (int) $u->id;
            if ((int) $u->role === 1 || (int) $u->is_admin === 1) {
                continue;
            }
            $code = $this->clip($u->mapid ?: null, 30);
            $user = ($code ? User::withTrashed()->where('member_code', $code)->orderBy('id')->first() : null)
                ?? User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $u->email))])->first();
            if (! $user) {
                $this->warnings[] = sprintf('brid %d: login #%d %s (%s) not found in users — not moved', $brid, $u->id, $u->username, $code ?? '—');
                continue;
            }
            $userByLogin[(int) $u->id] = (int) $user->id;
            $movedUsers[] = (int) $user->id;
            if ((int) $user->branch_id !== $poolId) {
                if ((int) $user->branch_id !== $newId) {
                    $this->warnings[] = sprintf('brid %d: login %s is on branch #%d (not the pool #%d) — left there', $brid, $user->email, $user->branch_id, $poolId);
                }
                continue;
            }
            DB::table('users')->where('id', $user->id)->update(['branch_id' => $newId]);
            $this->bump('logins moved');
        }

        // members entered by those logins
        $codes = [];
        foreach ($loginIds as $lid) {
            $codes = array_merge($codes, $this->memberCodesByLogin[$lid] ?? []);
        }
        foreach (array_chunk($codes, 1000) as $chunk) {
            $this->bump('members moved', DB::table('members')->whereIn('member_code', $chunk)->where('branch_id', $poolId)->update(['branch_id' => $newId]));
        }

        // stock: legacy ACTIVE rows of this branch, taken from the pool
        foreach ($this->stockByBrid[$brid] ?? [] as $prid => $s) {
            $productId = $this->products[(string) $prid] ?? null;
            $qty = round($s['qty'], 4);
            if (! $productId || $qty <= 0) {
                if (! $productId && $qty > 0) {
                    $this->warnings[] = sprintf('brid %d: legacy product prid %s (qty %s) has no catalog product — not moved', $brid, $prid, $qty);
                }
                continue;
            }
            // plain branch stock only (order_line_id 0) — other rows are customised-order pieces
            $poolStock = Stock::where('branch_id', $poolId)->where('catalog_product_id', $productId)->where('order_line_id', 0)->first();
            $take = $poolStock ? min((float) $poolStock->quantity, $qty) : 0.0;
            $short = round($qty - $take, 4);
            $mine = Stock::firstOrCreate(['branch_id' => $newId, 'catalog_product_id' => $productId, 'order_line_id' => 0],
                ['quantity' => 0, 'purity' => $poolStock?->purity, 'last_rate' => $poolStock?->last_rate ?? $s['rate']]);
            $mine->quantity = round((float) $mine->quantity + $qty, 4);
            $mine->save();
            $today = now()->toDateString();
            StockMovement::create(['branch_id' => $newId, 'catalog_product_id' => $productId, 'type' => 'transfer', 'qty_change' => $qty,
                'balance_after' => $mine->quantity, 'ref_type' => 'legacy_split', 'ref_id' => $brid, 'moved_on' => $today]);
            if ($poolStock && $take > 0) {
                $poolStock->quantity = round((float) $poolStock->quantity - $take, 4);
                $poolStock->save();
                StockMovement::create(['branch_id' => $poolId, 'catalog_product_id' => $productId, 'type' => 'transfer', 'qty_change' => -$take,
                    'balance_after' => $poolStock->quantity, 'ref_type' => 'legacy_split', 'ref_id' => $brid, 'moved_on' => $today]);
            }
            if ($short > 0) {
                $this->warnings[] = sprintf('brid %d: product %s needs %s but the pool branch #%d only had %s — branch credited in full, pool left at 0 (shortfall %s)', $brid, $prid, $qty, $poolId, $take, $short);
            }
            $mv = &$this->moved[$productId];
            $mv ??= ['name' => (string) (CatalogProduct::find($productId)?->name['en'] ?? $prid), 'qty' => 0.0, 'short' => 0.0];
            $mv['qty'] += $qty;
            $mv['short'] += $short;
            unset($mv);
        }
        if (isset($this->products['3'])) {
            Stock::firstOrCreate(['branch_id' => $newId, 'catalog_product_id' => $this->products['3'], 'order_line_id' => 0], ['quantity' => 0, 'purity' => null, 'last_rate' => 1]);
        }

        // contracts (bonds) + their contract rows + their QRs
        foreach (array_chunk($this->bondsByBrid[$brid] ?? [], 1000) as $ids) {
            $this->bump('bonds moved', DB::table('bonds')->whereIn('id', $ids)->where('branch_id', $poolId)->update(['branch_id' => $newId]));
            $this->bump('contracts moved', DB::table('member_contracts')->whereIn('contract_no', array_map(fn ($id) => 'LEG-' . $id, $ids))->where('branch_id', $poolId)->update(['branch_id' => $newId]));
            $this->bump('QRs moved', DB::table('redeemable_qrs')->whereIn('bond_id', $ids)->where('branch_id', $poolId)->update(['branch_id' => $newId]));
        }

        // RD entries: exact (bond, date) pairs billed at this branch
        foreach ($this->rdByBrid[$brid] ?? [] as $bondId => $dates) {
            $this->bump('RD entries moved', DB::table('rd_entries')->where('bond_id', $bondId)->where('branch_id', $poolId)->whereIn('paid_on', array_keys($dates))->update(['branch_id' => $newId]));
        }

        // sales invoices (id = legacy estid)
        foreach (array_chunk($this->estByBrid[$brid] ?? [], 1000) as $ids) {
            $this->bump('sales invoices moved', DB::table('sales_invoices')->whereIn('id', $ids)->where('branch_id', $poolId)->update(['branch_id' => $newId]));
        }

        // dealer (reseller) commission rows earned at this branch: bill + mapped uid + value, and the
        // earning login filled in (the importer could not resolve tbl_reseller_com.userid). The pool's
        // rows are read once into memory; each row moves at most once.
        if (! isset($this->poolResCom[$poolId])) {
            $this->poolResCom[$poolId] = [];
            DB::table('reseller_commissions')->where('branch_id', $poolId)->select('id', 'invoice_no', 'mapped_uid', 'com_value')->orderBy('id')
                ->chunk(5000, function ($rows) use ($poolId) {
                    foreach ($rows as $r) {
                        $this->poolResCom[$poolId][$r->invoice_no . '|' . $r->mapped_uid . '|' . $this->num($r->com_value)][] = (int) $r->id;
                    }
                });
        }
        foreach ($this->resComByBrid[$brid] ?? [] as $g) {
            $k = $g['billno'] . '|' . $g['mapped'] . '|' . $g['value'];
            if (empty($this->poolResCom[$poolId][$k])) {
                continue;
            }
            $ids = array_splice($this->poolResCom[$poolId][$k], 0, $g['n']);
            $upd = ['branch_id' => $newId];
            if (isset($userByLogin[$g['login']])) {
                $upd['user_id'] = $userByLogin[$g['login']];
            }
            $this->bump('dealer commissions moved', DB::table('reseller_commissions')->whereIn('id', $ids)->update($upd));
        }

        // IC commission rows billed by this branch (bond + bill number) — commission_ledger is the
        // table behind the commission_entries view
        foreach ($this->icByBrid[$brid] ?? [] as $bondId => $bills) {
            $this->bump('IC rows moved', DB::table('commission_ledger')->where('bond_id', $bondId)->where('branch_id', $poolId)->whereIn('invoice_no', array_keys($bills))->update(['branch_id' => $newId]));
        }

        if ($movedUsers) {
            $this->carryNewActivity($poolId, $newId, $movedUsers);
        }

        return $branch;
    }

    /**
     * Bills and renewals made IN THE NEW SYSTEM since go-live by a login that was still sitting on
     * the pool branch: their stock came out of the pool and their rows carry the pool's branch id.
     * Every such deduction is a stock_movements row with created_by = that login, so: the piece
     * count moves from the dealer's restored stock back to the pool (the dealer really spent it),
     * and the renewal / bill, its contracts and its commission rows go to the dealer's branch.
     */
    protected function carryNewActivity(int $poolId, int $newId, array $movedUsers): void
    {
        $since = $this->option('since') . ' 00:00:00';
        $moves = StockMovement::where('branch_id', $poolId)->whereIn('created_by', $movedUsers)->where('created_at', '>=', $since)->orderBy('id')->get();
        foreach ($moves as $mv) {
            $productId = (int) $mv->catalog_product_id;
            $qty = (float) $mv->qty_change;                    // negative = sold / renewed out of the pool
            $mine = Stock::firstOrCreate(['branch_id' => $newId, 'catalog_product_id' => $productId, 'order_line_id' => 0], ['quantity' => 0, 'last_rate' => 1]);
            $pool = Stock::where('branch_id', $poolId)->where('catalog_product_id', $productId)->where('order_line_id', 0)->first();
            $after = round((float) $mine->quantity + $qty, 4);
            if ($after < 0) {
                $this->warnings[] = sprintf('branch #%d: new-system movement #%d (%s) exceeds the restored stock by %s — floored at 0', $newId, $mv->id, $qty, -$after);
                $qty = -(float) $mine->quantity;
                $after = 0.0;
            }
            $mine->quantity = $after;
            $mine->save();
            if ($pool) {
                $pool->quantity = round((float) $pool->quantity - $qty, 4);
                $pool->save();
            }
            $today = now()->toDateString();
            StockMovement::create(['branch_id' => $newId, 'catalog_product_id' => $productId, 'type' => 'transfer', 'qty_change' => $qty,
                'balance_after' => $mine->quantity, 'ref_type' => 'legacy_split_activity', 'ref_id' => $mv->id, 'moved_on' => $today]);
            if ($pool) {
                StockMovement::create(['branch_id' => $poolId, 'catalog_product_id' => $productId, 'type' => 'transfer', 'qty_change' => -$qty,
                    'balance_after' => $pool->quantity, 'ref_type' => 'legacy_split_activity', 'ref_id' => $mv->id, 'moved_on' => $today]);
            }
            DB::table('stock_movements')->where('id', $mv->id)->update(['branch_id' => $newId]);
            $this->bump('new-system movements carried');

            if ($mv->ref_type === 'rd_entry' && $mv->ref_id) {
                $this->bump('new-system renewals carried', DB::table('rd_entries')->where('id', $mv->ref_id)->where('branch_id', $poolId)->update(['branch_id' => $newId]));
            } elseif (in_array($mv->ref_type, ['sale', 'sale_cancel'], true) && $mv->ref_id) {
                $inv = DB::table('sales_invoices')->where('id', $mv->ref_id)->first(['id', 'invoice_no', 'branch_id']);
                if ($inv && (int) $inv->branch_id === $poolId) {
                    DB::table('sales_invoices')->where('id', $inv->id)->update(['branch_id' => $newId]);
                    foreach (['bonds', 'member_contracts', 'reseller_commissions', 'commission_ledger'] as $t) {
                        DB::table($t)->where('invoice_no', $inv->invoice_no)->where('branch_id', $poolId)->update(['branch_id' => $newId]);
                    }
                    $this->bump('new-system bills carried');
                }
            }
        }
    }

    protected function report(): void
    {
        $this->newLine();
        foreach ($this->counts as $k => $v) {
            $this->line(sprintf('  %-26s %8d', $k, $v));
        }
        if ($this->moved) {
            $this->newLine();
            $this->line('  stock moved out of the pool branches (legacy ACTIVE rows):');
            foreach ($this->moved as $m) {
                $this->line(sprintf('    %-20s %16s%s', $m['name'], number_format($m['qty'], 4), $m['short'] > 0 ? '   SHORT ' . number_format($m['short'], 4) : ''));
            }
        }
        foreach ($this->fromPool as $poolId => $brids) {
            $b = Branch::withTrashed()->find($poolId);
            $cash = isset($this->products['3']) ? (float) Stock::where('branch_id', $poolId)->where('catalog_product_id', $this->products['3'])->where('order_line_id', 0)->value('quantity') : 0.0;
            $this->line(sprintf('  pool branch #%d %s: %d legacy branches split out of it · cash stock now ₹%s · logins left %d · members left %d',
                $poolId, $b?->name, count($brids), number_format($cash, 2), DB::table('users')->where('branch_id', $poolId)->count(), DB::table('members')->where('branch_id', $poolId)->count()));
        }
        $this->line(sprintf('  branches now: %d (with legacy_brid: %d)', Branch::count(), Branch::whereNotNull('legacy_brid')->count()));
        if ($this->warnings) {
            $this->newLine();
            $this->warn(count($this->warnings) . ' warning(s):');
            foreach (array_slice($this->warnings, 0, 60) as $w) {
                $this->line('  - ' . $w);
            }
            if (count($this->warnings) > 60) {
                $this->line('  … ' . (count($this->warnings) - 60) . ' more');
            }
        }
    }

    protected function bump(string $k, int $n = 1): void
    {
        $this->counts[$k] = ($this->counts[$k] ?? 0) + $n;
    }

    protected function key(?string $name, ?string $city): string
    {
        return Str::lower(trim((string) $name)) . '|' . Str::lower(trim((string) $city));
    }

    /** Same clean-up as LegacyRolloutImport::plain() — legacy names carry HTML and odd spacing. */
    protected function plain(?string $html): string
    {
        if ($html === null) {
            return '';
        }
        $t = preg_replace('/<br\s*\/?>/i', ', ', $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');

        return trim(preg_replace('/\s*,\s*,+/', ',', preg_replace('/\s+/', ' ', $t)), " ,\t\n\r");
    }

    protected function clip($val, int $len): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }

        return mb_substr(trim((string) $val), 0, $len) ?: null;
    }

    protected function num($v): float
    {
        $v = str_replace([',', ' '], '', (string) $v);

        return is_numeric($v) ? (float) $v : 0.0;
    }

    protected function date($d): ?string
    {
        if (! $d || str_starts_with((string) $d, '0000-00-00') || ! strtotime((string) $d)) {
            return null;
        }

        return date('Y-m-d', strtotime((string) $d));
    }
}
