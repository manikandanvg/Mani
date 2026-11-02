<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * User 2026-11-02: 1,038 legacy branches are all "LORD JEWELLER" in "SIVAKASI" (every area
 * dealer is registered under the HQ name), so the importer folded 1,037 of them into Head
 * Office. `legacy:split-merged-branches` gives each one its own branch again (keyed on
 * branches.legacy_brid) and moves its logins, members, legacy ACTIVE stock, margins and
 * contracts out of Head Office. Dry-run changes nothing; a second run is a no-op.
 */
class LegacySplitMergedBranchesTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $hq;

    protected Branch $sree;

    protected CatalogProduct $cash;

    protected CatalogProduct $gold;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Services\Activity\ActivityLogger::$enabled = true;

        // ---- a legacy database in memory ----
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('legacy');
        $s = Schema::connection('legacy');
        $s->create('tbl_branches', function (Blueprint $t) {
            $t->integer('brid'); $t->string('storename'); $t->string('address')->nullable(); $t->string('city')->nullable();
            $t->string('pincode')->nullable(); $t->string('phoneno')->nullable(); $t->string('brincharge')->nullable();
            $t->string('gstno')->nullable(); $t->integer('status')->default(1);
        });
        $s->create('ci_users', function (Blueprint $t) {
            $t->integer('id'); $t->string('username'); $t->string('firstname')->nullable(); $t->string('lastname')->nullable();
            $t->string('email')->nullable(); $t->string('mobile_no')->nullable(); $t->string('password')->nullable();
            $t->integer('role')->default(2); $t->string('brid')->default(''); $t->integer('is_active')->default(1); $t->integer('is_admin')->default(0);
            $t->string('mapid')->default(''); $t->string('uplineid')->default(''); $t->string('desiname')->default('');
            $t->string('billmargin')->default('0'); $t->string('goldgmmargin')->default('0'); $t->string('silvergmmargin')->default('0');
            $t->string('stocktransmargin')->default('0'); $t->string('latitude')->nullable(); $t->string('longitude')->nullable();
            $t->string('digicash')->default('0'); $t->string('invested')->default('0');
        });
        $s->create('tbl_member', function (Blueprint $t) { $t->integer('id'); $t->string('userid'); $t->integer('entryby'); });
        $s->create('tbl_stock', function (Blueprint $t) { $t->integer('branchid'); $t->integer('prid'); $t->string('catename'); $t->decimal('weight', 15, 4); $t->decimal('purprice', 15, 2)->default(1); $t->string('purity')->nullable(); $t->string('status'); });
        $s->create('tbl_bond', function (Blueprint $t) { $t->integer('bondid'); $t->integer('branchid'); });
        $s->create('tbl_rdentry', function (Blueprint $t) { $t->integer('trid'); $t->integer('bondid'); $t->string('trdate'); $t->integer('branchid'); });
        $s->create('tbl_salesinvoice', function (Blueprint $t) { $t->integer('estid'); $t->integer('branchid'); });
        $s->create('tbl_reseller_com', function (Blueprint $t) { $t->integer('transid'); $t->integer('brid'); $t->string('billno'); $t->string('userid'); $t->string('mappeduid')->nullable(); $t->string('comvalue'); });
        $s->create('tbl_iccom', function (Blueprint $t) { $t->integer('ictran'); $t->integer('bondno'); $t->string('billno'); $t->integer('billbybranch'); });

        $L = DB::connection('legacy');
        $ins = fn (string $table, array $rows) => array_map(fn ($r) => $L->table($table)->insert($r), $rows);   // rows differ in columns
        $ins('tbl_branches', [
            ['brid' => 1, 'storename' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'phoneno' => '04562', 'brincharge' => 'HQ'],
            ['brid' => 182, 'storename' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'address' => '62K Gandhi Road', 'pincode' => '626123', 'phoneno' => '9150283526', 'brincharge' => 'MURUGAN VEERASAMY / LJW24188'],
            ['brid' => 183, 'storename' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'phoneno' => '9843688244', 'brincharge' => 'BALASUNDARAM M / LJW24701'],
            ['brid' => 50, 'storename' => 'SREE GOLD', 'city' => 'Madurai', 'phoneno' => '9000000050', 'brincharge' => 'Sree'],
        ]);
        $ins('ci_users', [
            ['id' => 1, 'username' => 'admin', 'email' => 'admin@lordicl.com', 'role' => 1, 'is_admin' => 1, 'brid' => '1', 'mapid' => 'LJW01', 'desiname' => 'Reseller', 'billmargin' => '400', 'digicash' => '200'],
            ['id' => 10, 'username' => 'murugan', 'firstname' => 'Murugan', 'email' => 'murugan@test.local', 'brid' => '182', 'mapid' => 'LJW24188', 'uplineid' => 'LJW24701', 'desiname' => 'Area Dealer', 'billmargin' => '500', 'digicash' => '100', 'latitude' => '9.45', 'longitude' => '77.79'],
            ['id' => 11, 'username' => 'bala', 'firstname' => 'Bala', 'email' => 'bala@test.local', 'brid' => '183', 'mapid' => 'LJW24701', 'desiname' => 'Wholesaler', 'billmargin' => '-50', 'digicash' => '0'],
            ['id' => 12, 'username' => 'sree', 'firstname' => 'Sree', 'email' => 'sree@test.local', 'brid' => '50', 'mapid' => 'LJW24999', 'desiname' => 'Reseller'],
        ]);
        $L->table('tbl_member')->insert([
            ['id' => 1, 'userid' => 'M1', 'entryby' => 10], ['id' => 2, 'userid' => 'M2', 'entryby' => 11], ['id' => 3, 'userid' => 'M3', 'entryby' => 1],
        ]);
        $ins('tbl_stock', [
            ['branchid' => 1, 'prid' => 3, 'catename' => 'CASH', 'weight' => 100000, 'status' => 'ACTIVE'],
            ['branchid' => 182, 'prid' => 3, 'catename' => 'CASH', 'weight' => 20000, 'status' => 'ACTIVE'],
            ['branchid' => 182, 'prid' => 3, 'catename' => 'CASH', 'weight' => 999999, 'status' => 'PENDING'],   // never counted
            ['branchid' => 182, 'prid' => 11, 'catename' => 'GOLD', 'weight' => 5, 'purprice' => 7000, 'status' => 'ACTIVE'],
            ['branchid' => 183, 'prid' => 3, 'catename' => 'CASH', 'weight' => 7000, 'status' => 'ACTIVE'],
        ]);
        $L->table('tbl_bond')->insert([['bondid' => 500, 'branchid' => 182], ['bondid' => 501, 'branchid' => 1]]);
        $L->table('tbl_rdentry')->insert([
            ['trid' => 1, 'bondid' => 500, 'trdate' => '2026-01-10', 'branchid' => 182],
            ['trid' => 2, 'bondid' => 500, 'trdate' => '2026-02-10', 'branchid' => 1],     // paid at HQ — stays
        ]);
        $L->table('tbl_salesinvoice')->insert([['estid' => 1, 'branchid' => 182]]);
        $L->table('tbl_reseller_com')->insert([
            ['transid' => 1, 'brid' => 182, 'billno' => '3849', 'userid' => '10', 'mappeduid' => 'LJW24188', 'comvalue' => '150'],   // userid = ci_users.id
            ['transid' => 2, 'brid' => 1, 'billno' => '3849', 'userid' => '1', 'mappeduid' => 'LJW01', 'comvalue' => '75'],
        ]);
        $L->table('tbl_iccom')->insert([['ictran' => 1, 'bondno' => 500, 'billno' => '3457', 'billbybranch' => 182]]);

        // ---- the new system as the importer left it: everything on Head Office ----
        $this->hq = Branch::where('level', 'hq')->orderBy('id')->firstOrFail();
        $this->hq->forceFill(['name' => 'LORD JEWELLER', 'city' => 'Sivakasi', 'bill_margin' => 900, 'digi_cash_balance' => 300])->save();
        $this->sree = Branch::create(['name' => 'SREE GOLD', 'city' => 'Madurai', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $mk = fn (string $code, string $name, string $material) => CatalogProduct::create(['code' => $code, 'name' => ['en' => $name], 'material' => $material, 'is_active' => true]);
        $this->cash = $mk('3', 'CASH', 'cash');
        $this->gold = $mk('11', '1 g Gold', 'gold');
        Stock::create(['branch_id' => $this->hq->id, 'catalog_product_id' => $this->cash->id, 'quantity' => 127000, 'last_rate' => 1]);
        Stock::create(['branch_id' => $this->hq->id, 'catalog_product_id' => $this->gold->id, 'quantity' => 5, 'purity' => '916', 'last_rate' => 7000]);

        foreach ([['murugan@test.local', 'LJW24188', $this->hq], ['bala@test.local', 'LJW24701', $this->hq], ['sree@test.local', 'LJW24999', $this->sree]] as [$email, $code, $branch]) {
            User::create(['name' => $code, 'email' => $email, 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id, 'member_code' => $code])->assignRole('distributor');
        }
        $rank = Rank::where('depth', 0)->value('id');
        foreach (['M1', 'M2', 'M3'] as $code) {
            Member::create(['member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999), 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank, 'branch_id' => $this->hq->id]);
        }
        $plan = Plan::create(['code' => 'P209', 'name' => ['en' => 'G11 PLUS1'], 'plan_type' => 1, 'type' => 'rd', 'min_value' => 1500, 'allocation_bv' => 0, 'allocation_cont' => 100, 'is_contract' => true, 'is_active' => true, 'validity_months' => 11]);
        $m1 = Member::where('member_code', 'M1')->firstOrFail();
        foreach ([500, 501] as $id) {
            $b = new Bond(['member_id' => $m1->id, 'plan_id' => $plan->id, 'branch_id' => $this->hq->id, 'bond_date' => '2026-01-10', 'value' => 1500, 'invoice_no' => (string) $id, 'status' => 'active']);
            $b->id = $id;
            $b->save();
        }
        foreach (['2026-01-10', '2026-02-10'] as $d) {
            DB::table('rd_entries')->insert(['bond_id' => 500, 'member_id' => $m1->id, 'paid_on' => $d, 'value' => 1500, 'due_count' => 1, 'branch_id' => $this->hq->id, 'created_at' => $d, 'updated_at' => $d]);
        }
        foreach ([['LJW24188', 150], ['LJW01', 75]] as [$mapped, $value]) {   // as the importer left them: user_id unresolved, both on HQ
            DB::table('reseller_commissions')->insert(['bill_date' => '2026-01-10', 'invoice_no' => '3849', 'com_type_id' => 1, 'user_id' => null, 'branch_id' => $this->hq->id,
                'mapped_uid' => $mapped, 'com_value' => $value, 'status' => 'passed', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function cashOf(int $branchId): float
    {
        return (float) Stock::where('branch_id', $branchId)->where('catalog_product_id', $this->cash->id)->value('quantity');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = Branch::count();
        $this->artisan('legacy:split-merged-branches --dry-run')->assertSuccessful();

        $this->assertSame($before, Branch::count());
        $this->assertNull(Branch::where('legacy_brid', 182)->first());
        $this->assertNull($this->hq->fresh()->legacy_brid);
        $this->assertSame(127000.0, $this->cashOf($this->hq->id));
        $this->assertSame($this->hq->id, User::where('email', 'murugan@test.local')->value('branch_id'));
    }

    public function test_every_legacy_branch_gets_its_own_branch_with_its_logins_members_stock_and_contracts(): void
    {
        $before = Branch::count();
        $stockLogs = ActivityLog::where('category', 'stock')->count();
        $this->artisan('legacy:split-merged-branches')->assertSuccessful();

        // HQ and the unique-name branch are stamped, not duplicated; the two merged dealers are created
        $this->assertSame($before + 2, Branch::count());
        $this->assertSame(1, (int) $this->hq->fresh()->legacy_brid);
        $this->assertSame(50, (int) $this->sree->fresh()->legacy_brid);
        $b182 = Branch::where('legacy_brid', 182)->firstOrFail();
        $b183 = Branch::where('legacy_brid', 183)->firstOrFail();
        $this->assertSame('LORD JEWELLER', $b182->name);
        $this->assertSame('SIVAKASI', $b182->city);
        $this->assertSame('9150283526', $b182->phone);
        $this->assertSame('MURUGAN VEERASAMY / LJW24188', $b182->incharge);
        $this->assertSame('626123', $b182->pincode);
        $this->assertSame('area_dealer', $b182->level);
        $this->assertSame('wholesaler', $b183->level);
        $this->assertSame(Branch::KIND_DEALER, $b182->kind);
        $this->assertSame($b183->id, $b182->source_branch_id, 'supplier = the upline login\'s branch, created in the same run');
        $this->assertEquals(9.45, (float) $b182->latitude);

        // margins / digi cash: dealer's own on the dealer, taken off HQ (negative legacy margin = 0)
        $this->assertEquals(500, (float) $b182->bill_margin);
        $this->assertEquals(100, (float) $b182->digi_cash_balance);
        $this->assertEquals(0, (float) $b183->bill_margin);
        $hq = $this->hq->fresh();
        $this->assertEquals(400, (float) $hq->bill_margin);
        $this->assertEquals(200, (float) $hq->digi_cash_balance);

        // logins + members follow their branch; the HQ-entered member and the SREE GOLD login stay
        $this->assertSame($b182->id, User::where('email', 'murugan@test.local')->value('branch_id'));
        $this->assertSame($b183->id, User::where('email', 'bala@test.local')->value('branch_id'));
        $this->assertSame($this->sree->id, User::where('email', 'sree@test.local')->value('branch_id'));
        $this->assertSame($b182->id, Member::where('member_code', 'M1')->value('branch_id'));
        $this->assertSame($b183->id, Member::where('member_code', 'M2')->value('branch_id'));
        $this->assertSame($this->hq->id, Member::where('member_code', 'M3')->value('branch_id'));

        // stock: legacy ACTIVE rows only, taken out of HQ, with a movement on both sides
        $this->assertSame(20000.0, $this->cashOf($b182->id));
        $this->assertSame(7000.0, $this->cashOf($b183->id));
        $this->assertSame(100000.0, $this->cashOf($this->hq->id));
        $this->assertSame(5.0, (float) Stock::where('branch_id', $b182->id)->where('catalog_product_id', $this->gold->id)->value('quantity'));
        $this->assertSame(0.0, (float) Stock::where('branch_id', $this->hq->id)->where('catalog_product_id', $this->gold->id)->value('quantity'));
        $this->assertSame('916', Stock::where('branch_id', $b182->id)->where('catalog_product_id', $this->gold->id)->value('purity'));
        $this->assertSame(2, StockMovement::where('ref_type', 'legacy_split')->where('ref_id', 182)->where('catalog_product_id', $this->cash->id)->count());
        $this->assertEquals(-20000, (float) StockMovement::where('ref_type', 'legacy_split')->where('ref_id', 182)->where('branch_id', $this->hq->id)->where('catalog_product_id', $this->cash->id)->value('qty_change'));

        // contracts, RD entries (exact bond + date), nothing of HQ's own moves
        $this->assertSame($b182->id, Bond::find(500)->branch_id);
        $this->assertSame($this->hq->id, Bond::find(501)->branch_id);
        $this->assertSame($b182->id, (int) DB::table('rd_entries')->where('bond_id', 500)->where('paid_on', '2026-01-10')->value('branch_id'));
        $this->assertSame($this->hq->id, (int) DB::table('rd_entries')->where('bond_id', 500)->where('paid_on', '2026-02-10')->value('branch_id'));

        // dealer commission rows: matched on bill + mapped uid + value, earning login filled in; HQ's own row stays
        $mine = DB::table('reseller_commissions')->where('mapped_uid', 'LJW24188')->first();
        $this->assertSame($b182->id, (int) $mine->branch_id);
        $this->assertSame(User::where('email', 'murugan@test.local')->value('id'), (int) $mine->user_id);
        $this->assertSame($this->hq->id, (int) DB::table('reseller_commissions')->where('mapped_uid', 'LJW01')->value('branch_id'));

        // one summary line in the activity log, not thousands of rows
        $this->assertSame(1, ActivityLog::where('action', 'legacy_split')->count());
        $this->assertSame($stockLogs, ActivityLog::where('category', 'stock')->count());

        // idempotent
        $this->artisan('legacy:split-merged-branches')->assertSuccessful();
        $this->assertSame($before + 2, Branch::count());
        $this->assertSame(20000.0, $this->cashOf($b182->id));
        $this->assertSame(100000.0, $this->cashOf($this->hq->id));
        $this->assertSame(2, StockMovement::where('ref_type', 'legacy_split')->where('ref_id', 182)->where('catalog_product_id', $this->cash->id)->count());
    }
}
