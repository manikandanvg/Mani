<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5c — community leaderboards (rank members by a metric + own standing).
 */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    protected int $rankId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankId = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id;
    }

    protected function member(string $code, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'member_code' => $code, 'name' => "M{$code}", 'phone' => '90000000' . substr($code, -2),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $this->rankId, 'status' => 'active',
        ], $attrs));
    }

    public function test_member_only(): void
    {
        Sanctum::actingAs(Customer::create(['phone' => '9123123123']), ['*']);
        $this->getJson('/api/v1/community/leaderboard')->assertStatus(403);
    }

    public function test_ranks_by_bv_with_own_standing(): void
    {
        $a = $this->member('L01', ['bv' => 5000]);
        $this->member('L02', ['bv' => 9000]);
        $this->member('L03', ['bv' => 1000]);

        Sanctum::actingAs($a, ['*']);
        $res = $this->getJson('/api/v1/community/leaderboard?metric=bv')->assertOk()
            ->assertJsonPath('metric', 'bv');

        // Highest BV first.
        $this->assertSame('L02', $res->json('top.0.member_code'));
        $this->assertSame(1, $res->json('top.0.rank'));
        $this->assertSame('L03', $res->json('top.2.member_code'));

        // Caller (L01, bv 5000) is 2nd overall; their row is flagged mine.
        $this->assertSame(2, $res->json('me.rank'));
        $this->assertEquals(5000.0, $res->json('me.value'));
        $mine = collect($res->json('top'))->firstWhere('member_code', 'L01');
        $this->assertTrue($mine['mine']);
    }

    public function test_earnings_metric_uses_wallet(): void
    {
        $a = $this->member('L10', ['bv' => 1]);
        $b = $this->member('L11', ['bv' => 1]);
        MemberWallet::create(['member_id' => $a->id, 'currency_code' => 'INR', 'earning_total' => 2500]);
        MemberWallet::create(['member_id' => $b->id, 'currency_code' => 'INR', 'earning_total' => 8000]);

        Sanctum::actingAs($a, ['*']);
        $res = $this->getJson('/api/v1/community/leaderboard?metric=earnings')->assertOk();

        $this->assertSame('L11', $res->json('top.0.member_code'));
        $this->assertEquals(8000.0, $res->json('top.0.value'));
        $this->assertSame(2, $res->json('me.rank'));
        $this->assertEquals(2500.0, $res->json('me.value'));
    }

    public function test_inactive_members_excluded(): void
    {
        $a = $this->member('L20', ['bv' => 100]);
        $this->member('L21', ['bv' => 9999, 'status' => 'inactive']);

        Sanctum::actingAs($a, ['*']);
        $res = $this->getJson('/api/v1/community/leaderboard?metric=bv')->assertOk();

        $codes = collect($res->json('top'))->pluck('member_code');
        $this->assertNotContains('L21', $codes);
        $this->assertSame(1, $res->json('me.rank'));     // top among active
    }
}
