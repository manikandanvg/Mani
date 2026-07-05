<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use App\Models\WalletWithdrawal;
use App\Services\Lbox\WalletWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Closed-wallet withdrawal via the branch L-BOX QR: scan resolves the box, the
 * request atomically moves balance member→branch and queues the voice line, the
 * incharge disburses gold/cash, and a cancel refunds the wallet. No gateway.
 */
class WalletWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Device $device;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Rajapalayam', 'country' => 'IN', 'is_active' => true]);
        $this->device = Device::create([
            'name' => 'Counter Box', 'serial_no' => 'LBX-LITE-0001', 'board_type' => 'lite',
            'branch_id' => $this->branch->id, 'status' => 'active', 'pairing_code' => null,
        ]);

        // The branch must have OPENED today (incharge RFID tap) for withdrawals.
        \App\Models\BranchAttendance::create([
            'branch_id' => $this->branch->id, 'date' => now()->toDateString(),
            'device_id' => $this->device->id, 'opened_at' => now(),
        ]);

        $rank = Rank::create(['code' => 'MEMBER', 'name' => ['en' => 'Distributor'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'WD1', 'name' => 'Withdrawer', 'phone' => '9000000080',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id,
            'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        MemberWallet::create(['member_id' => $this->member->id, 'cash_balance' => 10000, 'earning_total' => 10000]);
    }

    public function test_scan_resolves_the_branch_and_withdrawal_moves_balance_and_announces(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        // resolve the QR (device uuid) → branch + current balance
        $this->getJson("/api/v1/member/wallet/qr/{$this->device->uuid}")
            ->assertOk()
            ->assertJsonPath('branch', 'Rajapalayam')
            ->assertJsonPath('wallet_balance', 10000);

        // an unknown/inactive QR is refused
        $this->getJson('/api/v1/member/wallet/qr/00000000-0000-0000-0000-000000000000')
            ->assertStatus(422);

        // withdraw 6,000
        $res = $this->postJson('/api/v1/member/wallet/withdraw', [
            'device_uuid' => $this->device->uuid, 'amount' => 6000,
        ])->assertStatus(201);

        $this->assertSame(4000.0, (float) $res->json('wallet_balance'));
        $this->assertSame('pending', $res->json('withdrawal.status'));

        $wallet = MemberWallet::find($this->member->id);
        $this->assertSame(4000.0, (float) $wallet->cash_balance);
        $this->assertSame(6000.0, (float) $wallet->withdrawn_total);

        // the box got the voice line
        $line = $this->device->announcements()->firstOrFail();
        $this->assertSame('withdrawal', $line->type);
        $this->assertStringContainsString('6,000', $line->message);
        $this->assertStringContainsString('Withdrawer', $line->message);

        // overdraw is refused and nothing changes
        $this->postJson('/api/v1/member/wallet/withdraw', [
            'device_uuid' => $this->device->uuid, 'amount' => 4001,
        ])->assertStatus(422);
        $this->assertSame(4000.0, (float) $wallet->fresh()->cash_balance);

        // history endpoint
        $this->getJson('/api/v1/member/wallet/withdrawals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 6000)
            ->assertJsonPath('data.0.branch', 'Rajapalayam');
    }

    public function test_incharge_disburses_gold_and_cancel_refunds_the_wallet(): void
    {
        $svc = app(WalletWithdrawalService::class);

        $first = $svc->request($this->member, $this->device->uuid, 3000);
        $second = $svc->request($this->member, $this->device->uuid, 2000);
        $this->assertSame(5000.0, (float) MemberWallet::find($this->member->id)->cash_balance);

        // disburse as gold
        $svc->disburse($first, 'gold', null, 'Handed 1g coin');
        $this->assertSame('disbursed', $first->fresh()->status);
        $this->assertSame('gold', $first->fresh()->disbursal_mode);

        // a disbursed row cannot be re-decided
        try {
            $svc->cancel($first->fresh());
            $this->fail('Disbursed withdrawal must not be cancellable.');
        } catch (\RuntimeException) {
        }

        // cancel refunds
        $svc->cancel($second, null, 'Member changed mind');
        $wallet = MemberWallet::find($this->member->id);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame(7000.0, (float) $wallet->cash_balance);
        $this->assertSame(3000.0, (float) $wallet->withdrawn_total);   // only the disbursed one counts

        $this->assertSame(2, WalletWithdrawal::count());
    }

    public function test_withdrawals_blocked_when_branch_not_opened_or_box_displaced(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        // branch not opened today → offline
        \App\Models\BranchAttendance::query()->delete();
        $this->postJson('/api/v1/member/wallet/withdraw', [
            'device_uuid' => $this->device->uuid, 'amount' => 100,
        ])->assertStatus(422);

        // reopen, but the box was MOVED from its anchor → offline
        \App\Models\BranchAttendance::create([
            'branch_id' => $this->branch->id, 'date' => now()->toDateString(), 'opened_at' => now(),
        ]);
        $this->device->update(['is_displaced' => true]);
        $res = $this->postJson('/api/v1/member/wallet/withdraw', [
            'device_uuid' => $this->device->uuid, 'amount' => 100,
        ])->assertStatus(422);
        $this->assertStringContainsString('moved', $res->json('message'));

        $this->assertSame(10000.0, (float) MemberWallet::find($this->member->id)->cash_balance);
    }

    public function test_withdrawal_to_a_box_without_branch_or_inactive_box_is_refused(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        $this->device->update(['status' => 'suspended']);
        $this->postJson('/api/v1/member/wallet/withdraw', [
            'device_uuid' => $this->device->uuid, 'amount' => 100,
        ])->assertStatus(422);

        $this->device->update(['status' => 'active', 'branch_id' => null]);
        $this->postJson('/api/v1/member/wallet/withdraw', [
            'device_uuid' => $this->device->uuid, 'amount' => 100,
        ])->assertStatus(422);

        $this->assertSame(10000.0, (float) MemberWallet::find($this->member->id)->cash_balance);
    }
}
