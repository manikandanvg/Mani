<?php

namespace Tests\Feature\Api;

use App\Models\CommissionLedger;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Rank;
use App\Models\SocialPost;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Final pre-live batch (board draft 2026-08-12): Indian INR grouping, KYC
 * verified badge, genealogy web page, meeting audience targeting, community
 * image posts + moderation, GAP monthly merge, coaching line, digi history,
 * preferences meta + API locale.
 */
class AppFinalBatchTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->makeMember('FB1', '9000000801');
    }

    protected function makeMember(string $code, string $phone, int $depth = 0): Member
    {
        $rank = Rank::firstOrCreate(
            ['code' => $depth === 0 ? 'MEMBER' : "R{$depth}"],
            ['name' => ['en' => $depth === 0 ? 'Distributor' : "Stage {$depth}"], 'depth' => $depth, 'target_bv' => $depth * 50000],
        );

        return Member::create([
            'member_code' => $code, 'name' => "Member {$code}", 'phone' => $phone,
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active',
            'rank_id' => $rank->id,
        ]);
    }

    public function test_indian_lakh_crore_grouping(): void
    {
        $this->assertSame('18,83,97,300.00', Money::group(188397300));
        $this->assertSame('54,87,300.00', Money::group(5487300));
        $this->assertSame('50,000', Money::group(50000, 0));
        $this->assertSame('999.50', Money::group(999.5));
        $this->assertSame('-1,00,000.00', Money::group(-100000));
        $this->assertSame('₹18,29,10,000.00', Money::inr(182910000));
        // Non-INR keeps Western grouping.
        $this->assertSame('188,397,300.00', Money::group(188397300, 2, 'USD'));
    }

    public function test_kyc_verified_flag_and_genealogy_web_page(): void
    {
        $this->member->update(['pan_verified' => true, 'aadhaar_verified' => true]);
        Sanctum::actingAs($this->member, ['*']);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('member.kyc.verified', true);

        $res = $this->getJson('/api/v1/member/genealogy')->assertOk();
        $url = $res->json('web_url');
        $this->assertNotNull($url);

        // The signed page renders the admin-style tree with the verified tick.
        $page = $this->get($url)->assertOk();
        $page->assertSee('FB1');
        $page->assertSee('icl-verified', false);

        // Tampered signature is rejected.
        $this->get(preg_replace('/signature=\w+/', 'signature=bad', $url))->assertForbidden();
    }

    public function test_meeting_rank_audience_filters_list(): void
    {
        Meeting::create([
            'title' => 'District strategy call', 'join_url' => 'https://zoom.us/j/1',
            'platform' => 'zoom', 'scheduled_at' => now()->addDay(), 'duration_min' => 60,
            'visibility' => 'members', 'audience_ranks' => [2, 4], 'is_published' => true,
        ]);
        Meeting::create([
            'title' => 'All-hands', 'join_url' => 'https://zoom.us/j/2',
            'platform' => 'zoom', 'scheduled_at' => now()->addDay(), 'duration_min' => 60,
            'visibility' => 'members', 'audience_ranks' => null, 'is_published' => true,
        ]);

        // Depth-0 distributor sees only the untargeted meeting…
        Sanctum::actingAs($this->member, ['*']);
        $titles = collect($this->getJson('/api/v1/meetings')->assertOk()->json('upcoming'))->pluck('title');
        $this->assertTrue($titles->contains('All-hands'));
        $this->assertFalse($titles->contains('District strategy call'));

        // …a District Admin (depth 2) — one of the picked ranks — sees both.
        $district = $this->makeMember('FB2', '9000000802', 2);
        Sanctum::actingAs($district, ['*']);
        $titles = collect($this->getJson('/api/v1/meetings')->assertOk()->json('upcoming'))->pluck('title');
        $this->assertTrue($titles->contains('District strategy call'));
    }

    public function test_community_photo_post_and_moderation(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->member, ['*']);

        $res = $this->post('/api/v1/community/posts', [
            'body' => 'Proud of my team today!',
            'photo' => UploadedFile::fake()->image('team.jpg', 600, 400),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertCount(1, $res->json('data.images'));
        $postId = $res->json('data.id');

        // Feed carries the image; hiding the post removes it from the app.
        $feed = $this->getJson('/api/v1/community/feed')->assertOk();
        $this->assertNotEmpty(collect($feed->json('data'))->firstWhere('id', $postId)['images']);

        SocialPost::findOrFail($postId)->update(['is_hidden' => true]);
        $feed = $this->getJson('/api/v1/community/feed')->assertOk();
        $this->assertNull(collect($feed->json('data'))->firstWhere('id', $postId));
        $this->getJson("/api/v1/community/posts/{$postId}")->assertNotFound();
    }

    public function test_status_coaching_line_reports_next_stage_shortfall(): void
    {
        Rank::firstOrCreate(['code' => 'TALUK_DIRECTOR'], ['name' => ['en' => 'Taluk Admin'], 'depth' => 1, 'target_bv' => 50000]);
        $this->member->update(['unpure_bv' => 20000]);

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson('/api/v1/member/status')->assertOk();

        $coaching = $res->json('coaching');
        $this->assertSame('Taluk Admin', $coaching['next']);
        // ₹30k own shortfall + ₹50k direct-leg shortfall (no directs yet).
        $this->assertEquals(80000.0, $coaching['needed_bv']);
        $this->assertNotEmpty($coaching['requirements']);
    }

    public function test_gap_merges_monthly_with_details(): void
    {
        foreach (['2026-08-03', '2026-08-19'] as $d) {
            CommissionLedger::create([
                'type' => 'GAP', 'member_id' => $this->member->id, 'amount' => 500,
                'net_amount' => 450, 'status' => 'pending', 'earned_on' => $d,
            ]);
        }
        CommissionLedger::create([
            'type' => 'GAP', 'member_id' => $this->member->id, 'amount' => 200,
            'net_amount' => 180, 'status' => 'paid', 'earned_on' => '2026-07-10',
        ]);

        Sanctum::actingAs($this->member, ['*']);
        $rows = $this->getJson('/api/v1/member/earnings?stream=GAP')->assertOk()->json('data');

        $this->assertCount(2, $rows);                       // one merged line per month
        $aug = collect($rows)->firstWhere('month', '2026-08');
        $this->assertEquals(1000.0, $aug['amount']);
        $this->assertSame(2, $aug['entries']);
        $this->assertSame('pending', $aug['status']);
        $this->assertSame('paid', collect($rows)->firstWhere('month', '2026-07')['status']);

        $details = $this->getJson('/api/v1/member/earnings/gap-details?month=2026-08')->assertOk();
        $this->assertCount(2, $details->json('rows'));
        $this->assertEquals(1000.0, $details->json('total'));
    }

    public function test_digimarket_history_is_paginated(): void
    {
        foreach (range(1, 35) as $i) {
            \App\Models\DigiGoldTxn::create([
                'member_id' => $this->member->id, 'metal' => 'gold', 'type' => 'credit',
                'grams' => 0.01, 'rate' => 10000, 'value' => 100, 'fee' => 0,
                'source' => 'buy_wallet', 'balance_after' => 0.01 * $i,
            ]);
        }

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson('/api/v1/digimarket/history')->assertOk();
        $this->assertCount(30, $res->json('data'));
        $this->assertSame(35, $res->json('total'));
    }

    public function test_preferences_meta_and_api_locale(): void
    {
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_default' => true, 'is_active' => true, 'sort' => 1]);
        Language::firstOrCreate(['code' => 'ta'], ['name' => 'Tamil', 'native_name' => 'தமிழ்', 'is_active' => true, 'sort' => 2]);
        Currency::firstOrCreate(['code' => 'INR'], ['name' => 'Indian Rupee', 'symbol' => '₹', 'rate_to_base' => 1, 'decimals' => 2, 'is_base' => true, 'is_active' => true]);

        $meta = $this->getJson('/api/v1/meta/preferences')->assertOk();
        $this->assertSame('INR', $meta->json('base_currency'));
        $this->assertContains('ta', collect($meta->json('languages'))->pluck('code')->all());
        $this->assertContains('INR', collect($meta->json('currencies'))->pluck('code')->all());

        // X-Locale localizes server content (CMS page title authored in Tamil).
        \App\Models\CmsPage::create([
            'slug' => 'about', 'title' => ['en' => 'About Us', 'ta' => 'எங்களை பற்றி'],
            'body' => ['en' => '<p>Hi</p>'], 'is_published' => true,
        ]);

        $this->assertSame('About Us', $this->getJson('/api/v1/pages/about')->json('title'));
        $this->assertSame(
            'எங்களை பற்றி',
            $this->getJson('/api/v1/pages/about', ['X-Locale' => 'ta'])->json('title'),
        );
    }
}
