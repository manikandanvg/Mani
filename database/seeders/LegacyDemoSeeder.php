<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Category;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Mou;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\Stock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Imports a curated demo slice from the legacy `lordicl` database (config connection
 * "legacy") into the new schema: MOUs, plans, live rate, branches, trade categories,
 * gold/silver catalog products + accumulated stock, and a sample member network.
 *
 * Deliberately skips the huge transactional history (188k iccom, 11k bonds/invoices)
 * — new sales are generated via the Sales screen. Re-runnable (keyed on natural ids).
 *
 *   php artisan db:seed --class=Database\\Seeders\\LegacyDemoSeeder
 */
class LegacyDemoSeeder extends Seeder
{
    protected $legacy;

    protected int $memberSampleSize = 200;

    /** legacy id => new id maps */
    protected array $mouMap = [];
    protected array $branchMap = [];
    protected array $catMap = [];     // legacy cateid => new category id (parent)
    protected array $subcatMap = [];  // legacy scateid => new category id (child)
    protected array $productMap = []; // legacy prid => new catalog_product id

    public function run(): void
    {
        $this->legacy = DB::connection('legacy');

        if (Rank::count() === 0) {
            $this->call(DatabaseSeeder::class);
        }

        $this->importLiveRate();
        $this->importMous();
        $this->importPlans();
        $this->importBranches();
        $this->importCategories();
        $this->importCatalogProducts();
        $this->importStock();
        $this->importMembers();

        $this->command?->info('Legacy demo import complete.');
    }

    protected function importLiveRate(): void
    {
        $r = $this->legacy->table('tbl_liverate')->first();
        if ($r) {
            LiveRate::updateOrCreate(
                ['country' => 'IN'],
                ['gold' => (float) $r->gold, 'silver' => (float) $r->silver, 'diamond' => (float) $r->diamond,
                 'source' => 'manual', 'is_override' => true, 'effective_at' => now()],
            );
        }
    }

    protected function importMous(): void
    {
        foreach ($this->legacy->table('tbl_mou')->get() as $m) {
            $mou = Mou::create([
                'title' => ['en' => $m->moutitle ?: ('MOU ' . $m->mouid)],
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
            Plan::updateOrCreate(
                ['code' => 'P' . $p->planid],
                [
                    'name' => ['en' => $p->planname],
                    'plan_type' => $type ?: 1,
                    'type' => match ($type) { 1 => 'rd', 2 => 'digital', 3 => 'gold', 4 => 'silver', default => 'others' },
                    'min_value' => (float) $p->minvalue,
                    'allocation_pct' => (float) $p->allocation,
                    'validity_months' => (int) ($p->levelcomduration ?: 12),
                    'cbc_value' => (float) $p->cbvalue,
                    'cbc_count' => (int) $p->cbcount,
                    'level_depth' => (int) $p->leveldepth,
                    'ic_schedule' => $this->numbers($p->iccom),
                    'level_schedule' => $this->numbers($p->levelcom),
                    'level_com_duration' => (int) $p->levelcomduration,
                    'billing_margin' => (float) $p->billingmargin,
                    'gm_margin' => (float) $p->gmmargin,
                    'stock_trans_margin' => (float) $p->stocktransmargin,
                    'epin_count' => (int) $p->epincount,
                    'mou_id' => $this->mouMap[$p->moutype] ?? null,
                    'is_active' => (string) $p->planvisible === '1',
                ],
            );
        }
    }

    protected function importBranches(): void
    {
        // legacy brid=1 == Head Office (already seeded as id 1) — reuse it
        $this->branchMap[1] = Branch::min('id');

        foreach ($this->legacy->table('tbl_branches')->get() as $b) {
            if ((int) $b->brid === 1) {
                continue;
            }
            $branch = Branch::create([
                'name' => $this->clip($b->storename ?: ('Branch ' . $b->brid), 200),
                'address' => $this->clip($b->address, 255), 'city' => $this->clip($b->city, 120),
                'pincode' => $this->clip($b->pincode, 12),
                'country' => 'IN', 'phone' => $this->clip($b->phoneno, 20),
                'incharge' => $this->clip($b->brincharge, 200),
                'gst_no' => $this->clip($b->gstno, 25), 'is_active' => (int) $b->status === 1,
            ]);
            $this->branchMap[$b->brid] = $branch->id;
        }
    }

    protected function importCategories(): void
    {
        // parent categories (GOLD/SILVER) under the trade domain
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

        // sub-categories from distinct stock (scateid/scatename) for gold/silver
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
        // one representative row per product (gold/silver only)
        $rows = $this->legacy->table('tbl_stock')
            ->whereIn('catename', ['GOLD', 'SILVER'])
            ->get()
            ->unique('prid');

        // GM redemption margin (₹/gram) and HSN code live on tbl_product, not tbl_stock.
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
                    'making_charge_pct' => (float) $r->mcharge,
                    'wastage_charge_pct' => (float) $r->wcharge,
                    'hallmark_charge' => (float) $r->acharge,
                    'gst_pct' => (float) ($r->gsti ?: 3),
                    'gm_margin' => (float) ($info->margin ?? 0),          // tbl_product.margin (₹/gram)
                    'hsn_code' => $this->clip($info->hsncode ?? null, 20),
                    'is_active' => true,
                ],
            );
            $this->productMap[$r->prid] = $cp->id;

            // Placeholder stock-transfer margin rate card (admin fills the per-level %).
            \App\Models\StockTransferMargin::firstOrCreate(['catalog_product_id' => $cp->id]);
        }
    }

    protected function importStock(): void
    {
        // accumulate weight per (branch, product) from legacy stock
        $rows = $this->legacy->table('tbl_stock')
            ->select('branchid', 'prid', 'purity', DB::raw('SUM(weight) as qty'), DB::raw('MAX(purprice) as rate'))
            ->whereIn('catename', ['GOLD', 'SILVER'])
            ->where('weight', '>', 0)
            ->groupBy('branchid', 'prid', 'purity')
            ->get();

        foreach ($rows as $r) {
            $branchId = $this->branchMap[$r->branchid] ?? null;
            $productId = $this->productMap[$r->prid] ?? null;
            if (! $branchId || ! $productId) {
                continue;
            }
            $stock = Stock::firstOrNew(['branch_id' => $branchId, 'catalog_product_id' => $productId]);
            $stock->quantity = (float) $stock->quantity + (float) $r->qty;
            $stock->purity = $this->clip($r->purity ?: $stock->purity, 12);
            $stock->last_rate = (float) $r->rate;
            $stock->save();
        }
    }

    protected function importMembers(): void
    {
        $rankId = Rank::where('depth', 0)->value('id');
        $rows = $this->legacy->table('tbl_member')
            ->whereNotNull('userid')->where('userid', '!=', '')
            ->orderBy('id')->limit($this->memberSampleSize)->get();

        // pass 1: insert members keyed by code
        foreach ($rows as $m) {
            $member = Member::firstOrCreate(
                ['member_code' => $this->clip($m->userid, 30)],
                [
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
                    'nominee_name' => $this->clip($m->nomineename ?: null, 200),
                    'nominee_age' => is_numeric($m->nomineeage) ? (int) $m->nomineeage : null,
                    'nominee_relation' => $m->nomineerel ?: null,
                    'joined_on' => $this->date($m->doj) ?? now()->toDateString(),
                    'placement' => 'level',
                    'rank_id' => $rankId,
                    'bv' => (float) $m->mybv,
                    'gbv' => (float) $m->mygbv,
                    'unpure_bv' => (float) ($m->unpurebv ?? 0),
                    'unpure_gbv' => (float) ($m->unpuregbv ?? 0),
                    'downline_count' => (int) $m->lvldownlinecount,
                    'status' => (int) $m->status === 1 ? 'active' : 'inactive',
                ],
            );
            MemberWallet::firstOrCreate(['member_id' => $member->id], [
                'cash_balance' => (float) ($m->walletamount ?? 0),
                'coupon_balance' => (float) ($m->couponwallet ?? 0),
            ]);
        }

        // pass 2: link upline/referrer by code (only within the imported sample)
        $byCode = Member::pluck('id', 'member_code');
        foreach ($rows as $m) {
            $updates = [];
            if ($m->uplineusername && isset($byCode[$m->uplineusername])) {
                $updates['upline_id'] = $byCode[$m->uplineusername];
            }
            if ($m->refererusername && isset($byCode[$m->refererusername])) {
                $updates['referrer_id'] = $byCode[$m->refererusername];
            }
            if ($updates) {
                Member::where('member_code', $m->userid)->update($updates);
            }
        }
    }

    // ---- helpers ----

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
            return $val === '' ? null : null;
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
        if (! $d || $d === '0000-00-00' || ! strtotime((string) $d)) {
            return null;
        }

        return date('Y-m-d', strtotime((string) $d));
    }
}
