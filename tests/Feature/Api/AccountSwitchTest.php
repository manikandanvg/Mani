<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Member;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-account holders (board 2026-08-11): one phone may own several member
 * accounts; the app lists them and swaps the session between them. Switching
 * is allowed ONLY between accounts sharing the login phone.
 */
class AccountSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected Member $first;

    protected Member $second;

    protected Member $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $mk = fn (string $code, string $phone) => Member::create([
            'member_code' => $code, 'name' => 'Holder ' . $code, 'phone' => $phone,
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);

        // Same holder, two accounts — one stored with the country code.
        $this->first = $mk('ACC1', '9000000400');
        $this->second = $mk('ACC2', '+919000000400');
        $this->stranger = $mk('ACC3', '9000000500');
    }

    public function test_accounts_lists_every_member_on_the_same_phone(): void
    {
        $token = $this->first->createToken('phone-a')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/v1/me/accounts')->assertOk();

        $codes = collect($res->json('data'))->pluck('member_code')->all();
        $this->assertSame(['ACC1', 'ACC2'], $codes);
        $this->assertTrue(collect($res->json('data'))->firstWhere('member_code', 'ACC1')['current']);
        $this->assertFalse(collect($res->json('data'))->firstWhere('member_code', 'ACC2')['current']);
    }

    public function test_switch_swaps_the_session_and_revokes_the_old_token(): void
    {
        $token = $this->first->createToken('phone-a')->plainTextToken;

        $res = $this->withToken($token)
            ->postJson('/api/v1/me/switch', ['member_id' => $this->second->id])
            ->assertOk()
            ->assertJsonPath('mode', 'distributor')
            ->assertJsonPath('member.member_code', 'ACC2');

        // The new token acts as the sibling… (forgetGuards: the test kernel caches
        // the resolved sanctum user between requests within one test)
        $this->app['auth']->forgetGuards();
        $this->withToken($res->json('token'))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('member.member_code', 'ACC2');

        // …and the old token is dead.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_switching_to_an_unrelated_account_is_refused(): void
    {
        $token = $this->first->createToken('phone-a')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/me/switch', ['member_id' => $this->stranger->id])
            ->assertStatus(404);

        // Session unchanged.
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('member.member_code', 'ACC1');
    }

    public function test_customers_have_no_accounts_area(): void
    {
        $customer = Customer::create(['phone' => '9000000600', 'name' => 'Shopper']);
        $token = $customer->createToken('phone-c')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me/accounts')->assertStatus(403);
        $this->withToken($token)->postJson('/api/v1/me/switch', ['member_id' => $this->first->id])->assertStatus(403);
    }
}
