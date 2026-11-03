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
}
