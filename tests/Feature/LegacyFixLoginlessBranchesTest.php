<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\Rank;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * User 2026-11-03: branches without "View as Dealer". A dealer with two legacy logins under
 * one member code gets a second users row on the second branch; empty legacy placeholder
 * branches are deactivated; a login-less branch that holds data is left for the board.
 */
class LegacyFixLoginlessBranchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_logins_are_created_and_empty_placeholders_deactivated(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('legacy');
        Schema::connection('legacy')->create('ci_users', function (Blueprint $t) {
            $t->integer('id'); $t->string('username'); $t->string('firstname')->nullable(); $t->string('lastname')->nullable();
            $t->string('email')->nullable(); $t->string('mobile_no')->nullable(); $t->string('password')->nullable();
            $t->integer('role')->default(5); $t->string('brid')->default(''); $t->integer('is_active')->default(1); $t->integer('is_admin')->default(0);
            $t->string('mapid')->default(''); $t->string('desiname')->default(''); $t->string('invested')->default('0');
        });
        $L = DB::connection('legacy');
        $hash = password_hash('legacy-pw', PASSWORD_BCRYPT, ['cost' => (int) config('hashing.bcrypt.rounds', 10)]);   // the hashed cast refuses a cost above the app's
        foreach ([
            ['id' => 41, 'username' => 'LORD JEWELLER ', 'firstname' => 'LORD', 'lastname' => 'JEWELLER', 'email' => 'lordicl@ljw24999', 'mobile_no' => '9000000001', 'password' => $hash, 'brid' => '25', 'mapid' => 'LJW24999', 'desiname' => 'Reseller'],
            ['id' => 449, 'username' => 'SATHISH PAUL M', 'firstname' => 'SATHISH', 'lastname' => 'PAUL M', 'email' => 'sathish@example.com', 'mobile_no' => '9000000001', 'password' => $hash, 'brid' => '439', 'mapid' => 'LJW24999', 'desiname' => 'AREA DEALER'],
            ['id' => 44, 'username' => 'OLD INACTIVE', 'email' => 'old@example.com', 'password' => $hash, 'brid' => '27', 'mapid' => 'LJW241026', 'is_active' => 0],
            ['id' => 1, 'username' => 'admin', 'email' => 'admin@legacy', 'password' => $hash, 'brid' => '1', 'role' => 1, 'is_admin' => 1],
        ] as $row) {
            $L->table('ci_users')->insert($row);   // one by one: the rows carry different column sets
        }

        $hq = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'Sivakasi', 'country' => 'IN', 'level' => 'hq', 'legacy_brid' => 1, 'is_active' => true]);
        $reseller = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'LJW24999', 'country' => 'IN', 'level' => 'reseller', 'legacy_brid' => 25, 'is_active' => true]);
        $area = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'country' => 'IN', 'level' => 'area_dealer', 'legacy_brid' => 439, 'is_active' => true]);
        $inactiveLogin = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'X', 'country' => 'IN', 'level' => 'area_dealer', 'legacy_brid' => 27, 'is_active' => true]);
        $empty = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'incharge' => 'PRIYANKA K C / LJW24693982077', 'country' => 'IN', 'level' => 'area_dealer', 'legacy_brid' => 542, 'is_active' => true]);
        $withData = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'country' => 'IN', 'level' => 'area_dealer', 'legacy_brid' => 999, 'is_active' => true]);
        Member::create(['member_code' => 'LJW24001', 'name' => 'Someone', 'phone' => '9000000009', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id'), 'branch_id' => $withData->id]);

        // what legacy:rollout produced: ONE login per member code, on the LATER ci_users row's branch
        $primary = User::create(['name' => 'SATHISH PAUL M', 'email' => 'sathish.paul.m@dealer.lordicl.local', 'password' => 'x', 'status' => 'active', 'branch_id' => $area->id, 'member_code' => 'LJW24999']);
        $primary->assignRole('distributor');
        $old = User::create(['name' => 'OLD INACTIVE', 'email' => 'old@example.com', 'password' => 'x', 'status' => 'inactive', 'branch_id' => $inactiveLogin->id, 'member_code' => 'LJW241026']);
        $old->assignRole('distributor');

        // (the seeder's own demo branches have no login either, so totals are not asserted)
        \Illuminate\Support\Facades\Artisan::call('legacy:fix-loginless-branches', ['--dry-run' => true]);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('would create 1 login(s)', $out);
        $this->assertMatchesRegularExpression('/branch #' . $empty->id . ' .*empty → inactive/u', $out);
        $this->assertMatchesRegularExpression('/branch #' . $withData->id . ' .*holds 1 members/u', $out);
        $this->assertStringNotContainsString('branch #' . $inactiveLogin->id . ' ', $out);
        $this->assertStringNotContainsString('branch #' . $hq->id . ' ', $out);
        $this->assertNull(User::where('branch_id', $reseller->id)->first());
        $this->assertTrue($empty->fresh()->is_active);

        $this->artisan('legacy:fix-loginless-branches')->expectsOutputToContain('created 1 login(s)')->assertSuccessful();

        // the second login: own branch, same code, legacy hash, slug.id e-mail (legacy e-mail was invalid)
        $second = User::where('branch_id', $reseller->id)->firstOrFail();
        $this->assertSame('LJW24999', $second->member_code);
        $this->assertSame('lord.jeweller.41@dealer.lordicl.local', $second->email);
        $this->assertTrue($second->hasRole('distributor'));
        $this->assertSame('active', $second->status);
        $this->assertTrue(password_verify('legacy-pw', $second->password));
        $this->assertTrue($reseller->fresh()->distributorUser()->exists(), 'View as Dealer now shows');
        // the pre-existing login stays the primary one for by-code lookups
        $this->assertSame($primary->id, User::where('member_code', 'LJW24999')->orderBy('id')->value('id'));
        $this->assertSame($area->id, app(\App\Services\DealershipService::class)->branchOf(Member::create(['member_code' => 'LJW24999', 'name' => 'S', 'phone' => '9000000001', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id')]))?->id);

        // the inactive-login branch is untouched (has a login, just inactive); HQ untouched
        $this->assertSame(1, User::where('branch_id', $inactiveLogin->id)->count());
        $this->assertTrue($hq->fresh()->is_active);
        // placeholders: the empty one is off, the one holding a member stays active
        $this->assertFalse($empty->fresh()->is_active);
        $this->assertTrue($withData->fresh()->is_active);

        // idempotent
        $this->artisan('legacy:fix-loginless-branches')->expectsOutputToContain('created 0 login(s)')->expectsOutputToContain('deactivated 0 empty')->assertSuccessful();
        $this->assertSame(2, User::where('member_code', 'LJW24999')->count(), 'still exactly two logins for the code');
    }

    /** User 2026-11-20: no second login / new e-mail — the login-less branch is merged into the login's branch. */
    public function test_second_branch_is_merged_into_the_login_branch(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('legacy');
        Schema::connection('legacy')->create('ci_users', function (Blueprint $t) {
            $t->integer('id'); $t->string('username'); $t->string('firstname')->nullable(); $t->string('lastname')->nullable();
            $t->integer('role')->default(5); $t->string('brid')->default(''); $t->integer('is_admin')->default(0); $t->string('mapid')->default(''); $t->string('mobile_no')->nullable();
        });
        foreach ([['id' => 428, 'username' => 'PRIYANKA', 'brid' => '416', 'mapid' => 'LJW24777', 'mobile_no' => '9442900000'], ['id' => 531, 'username' => 'PRIYANKA', 'brid' => '523', 'mapid' => 'LJW24777', 'mobile_no' => '9442900000']] as $row) {
            DB::connection('legacy')->table('ci_users')->insert($row);
        }

        Branch::create(['name' => 'LORD JEWELLER', 'city' => 'Sivakasi', 'country' => 'IN', 'level' => 'hq', 'legacy_brid' => 1, 'is_active' => true]);
        // $login = the superior position (Wholesaler) that must survive; the login sits on the Area Distributor branch
        $login = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'A', 'country' => 'IN', 'level' => 'wholesaler', 'legacy_brid' => 416, 'is_active' => true, 'bill_margin' => 900]);
        $second = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'B', 'country' => 'IN', 'level' => 'area_dealer', 'legacy_brid' => 523, 'is_active' => true, 'bill_margin' => 27715]);
        $twin = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'B', 'country' => 'IN', 'level' => 'area_dealer', 'legacy_brid' => 542, 'is_active' => true, 'phone' => '9442900000', 'incharge' => 'PRIYANKA / LJW24777']);
        $user = User::create(['name' => 'PRIYANKA', 'email' => 'priyanka@dealer.lordicl.local', 'phone' => '9442900000', 'password' => 'x', 'status' => 'active', 'branch_id' => $second->id, 'member_code' => 'LJW24777']);
        $user->assignRole('distributor');
        $member = Member::create(['member_code' => 'LJW24001', 'name' => 'Someone', 'phone' => '9000000009', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id'), 'branch_id' => $second->id]);
        $product = \App\Models\CatalogProduct::create(['code' => 'MG1', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'is_active' => true])->id;
        \App\Models\Stock::create(['branch_id' => $login->id, 'catalog_product_id' => $product, 'quantity' => 17]);
        \App\Models\Stock::create(['branch_id' => $second->id, 'catalog_product_id' => $product, 'quantity' => 3]);
        // one unpaid margin row of the second branch: the only margin that may show after the merge
        \App\Models\ResellerCommission::create(['branch_id' => $second->id, 'com_type_id' => \App\Models\ResellerCommission::COM_RD_RENEWAL_MARGIN,
            'invoice_no' => 'INV-1', 'bill_date' => '2026-11-10', 'com_value' => 250, 'status' => 'passed', 'source' => 'new']);

        // dry run writes nothing
        $this->artisan('legacy:merge-second-branches')->expectsOutputToContain('1 branch(es) would be merged')->assertSuccessful();
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame($second->id, $member->fresh()->branch_id);

        $this->artisan('legacy:merge-second-branches', ['--apply' => true])->expectsOutputToContain('1 branch(es) merged')->assertSuccessful();

        $this->assertEquals(20, (float) \App\Models\Stock::where('branch_id', $login->id)->where('catalog_product_id', $product)->value('quantity'));
        $this->assertEquals(0, (float) \App\Models\Stock::where('branch_id', $second->id)->sum('quantity'));
        $this->assertSame($login->id, $member->fresh()->branch_id);
        $this->assertTrue(Branch::withTrashed()->find($second->id)->trashed(), 'the lower position is deleted');
        $this->assertTrue(Branch::withTrashed()->find($twin->id)->trashed(), 'the empty placeholder of the same person goes too');
        $this->assertSame($login->id, $user->fresh()->branch_id, 'the login follows to the superior branch');
        $this->assertEquals(250, (float) $login->fresh()->bill_margin, 'old balances dropped: pending margin only');
        $this->assertEquals(0, (float) Branch::withTrashed()->find($second->id)->bill_margin);
        $this->assertSame('priyanka@dealer.lordicl.local', $user->fresh()->email);
        $this->assertSame(1, User::where('member_code', 'LJW24777')->count(), 'no second login');

        // idempotent, and the login-fix command no longer wants a login for the merged branch
        $this->artisan('legacy:merge-second-branches', ['--apply' => true])->expectsOutputToContain('0 branch(es) merged')->assertSuccessful();
    }
}
