<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cut-over rule (board 2026-11-01): pending legacy digi-gold withdrawal requests are paid
 * into the member's CASH wallet at the worth fixed at request time; the dealer-side queue
 * ticket closes with them. docs/MIGRATION-OCT-CLOSING.md §9.
 */
class LegacySettleDigiWithdrawalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Services\Activity\ActivityLogger::$enabled = true;
    }

    protected function member(string $code): Member
    {
        $m = Member::create([
            'member_code' => $code, 'name' => 'Name ' . $code, 'phone' => '9' . str_pad((string) random_int(0, 999999999), 9, '0'),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::orderBy('depth')->firstOrFail()->id, 'status' => 'active',
        ]);
        MemberWallet::create(['member_id' => $m->id, 'cash_balance' => 100]);

        return $m;
    }

    public function test_pending_requests_are_credited_to_cash_wallet_and_queue_tickets_closed(): void
    {
        $a = $this->member('LJWA');
        $b = $this->member('LJWB');
        DB::table('digi_withdrawals')->insert([
            ['member_id' => $a->id, 'gold_wt' => 5.3681, 'worth' => 70000, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['member_id' => $a->id, 'gold_wt' => 1.0000, 'worth' => 13000, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['member_id' => $b->id, 'gold_wt' => 2.0000, 'worth' => 26000, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],   // already handled in legacy
        ]);
        DB::table('digi_queue')->insert([
            ['member_id' => $a->id, 'route' => 'DIGIWD', 'qr_mode' => 'cash', 'qr_code' => 'Q1', 'gram_worth' => 5.3681, 'cash_worth' => 70000, 'delivery_status' => 'pending', 'reference' => '57965', 'created_at' => now(), 'updated_at' => now()],
            ['member_id' => $a->id, 'route' => 'INVOICE', 'qr_mode' => 'gold', 'qr_code' => 'Q2', 'gram_worth' => 1.0000, 'cash_worth' => 13000, 'delivery_status' => 'pending', 'reference' => 'INV1', 'created_at' => now(), 'updated_at' => now()],   // a gold QR, not a withdrawal — untouched
        ]);

        $this->artisan('legacy:settle-digi-withdrawals', ['--dry-run' => true])->assertSuccessful();
        $this->assertEquals(100.0, (float) MemberWallet::where('member_id', $a->id)->value('cash_balance'), 'dry run writes nothing');

        $this->artisan('legacy:settle-digi-withdrawals')->assertSuccessful();

        $this->assertEquals(100 + 70000 + 13000, (float) MemberWallet::where('member_id', $a->id)->value('cash_balance'));
        $this->assertEquals(100.0, (float) MemberWallet::where('member_id', $b->id)->value('cash_balance'), 'approved rows are legacy history, not paid again');
        $this->assertSame(0, DB::table('digi_withdrawals')->where('status', 'pending')->count());
        $this->assertSame(2, DB::table('digi_withdrawals')->where('member_id', $a->id)->where('status', 'delivered')->count());
        $this->assertSame('redeemed', DB::table('digi_queue')->where('qr_code', 'Q1')->value('delivery_status'), 'the dealer ticket for the same request is closed');
        $this->assertSame('pending', DB::table('digi_queue')->where('qr_code', 'Q2')->value('delivery_status'), 'gold QRs are not withdrawal tickets');
        $this->assertTrue(ActivityLog::where('action', 'legacy_digi_withdrawals_settled')->exists());

        // idempotent: a second run finds nothing
        $this->artisan('legacy:settle-digi-withdrawals')->assertSuccessful();
        $this->assertEquals(100 + 70000 + 13000, (float) MemberWallet::where('member_id', $a->id)->value('cash_balance'));
    }
}
