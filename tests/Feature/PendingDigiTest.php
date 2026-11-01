<?php

namespace Tests\Feature;

use App\Filament\Pages\PendingDigi;
use App\Models\DigiGoldTxn;
use App\Models\DigiOrder;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-11-01 (night): the legacy digi-gold opening balance waits on Commissions →
 * Pending Digi; the purse stays empty until Head Office sends it.
 */
class PendingDigiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->actingAs($this->admin);
        $this->get('/admin/login');
        \App\Services\Activity\ActivityLogger::$enabled = true;
    }

    protected function member(string $code): Member
    {
        $m = Member::create(['member_code' => $code, 'name' => 'Legacy ' . $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id')]);
        MemberWallet::create(['member_id' => $m->id, 'digi_gold_grams' => 0]);

        return $m;
    }

    public function test_opening_balances_wait_and_reach_the_purse_only_when_sent(): void
    {
        $a = $this->member('LJW801');
        $b = $this->member('LJW802');
        $c = $this->member('LJW803');
        $oa = DigiOrder::create(['ref_no' => 'LEGACY-LJW801', 'member_id' => $a->id, 'source' => 'OPENING', 'status' => 'pending',
            'gold_wt' => 2.5, 'value' => 25000, 'rate_on_date' => 10000, 'created_at' => '2026-10-23']);
        $ob = DigiOrder::create(['ref_no' => 'LEGACY-LJW802', 'member_id' => $b->id, 'source' => 'OPENING', 'status' => 'pending',
            'gold_wt' => 0.75, 'value' => 9000, 'rate_on_date' => 12000, 'created_at' => '2026-09-01']);
        $zero = DigiOrder::create(['ref_no' => 'LEGACY-LJW803', 'member_id' => $c->id, 'source' => 'OPENING', 'status' => 'pending',
            'gold_wt' => 0, 'value' => 0, 'rate_on_date' => 0, 'created_at' => '2026-08-01']);

        $this->get('/admin/pending-digi')->assertSuccessful()->assertSee('Pending Digi');
        $this->assertSame(0, DigiGoldTxn::count());

        Livewire::test(PendingDigi::class)
            ->assertCanSeeTableRecords([$oa, $ob])
            ->assertCanNotSeeTableRecords([$zero])          // 0 g — nothing to send
            ->callTableAction('send', $oa)
            ->assertHasNoTableActionErrors();

        $this->assertEquals(2.5, (float) MemberWallet::find($a->id)->digi_gold_grams);
        $this->assertEquals(0.0, (float) MemberWallet::find($b->id)->digi_gold_grams, 'untouched until sent');
        $txn = DigiGoldTxn::where('member_id', $a->id)->firstOrFail();
        $this->assertSame('legacy_opening', $txn->source);
        $this->assertSame('LEGACY-LJW801', $txn->reference);
        $this->assertEquals(2.5, (float) $txn->grams);
        $this->assertEquals(10000.0, (float) $txn->rate);
        $this->assertEquals(25000.0, (float) $txn->value);
        $this->assertEquals(2.5, (float) $txn->balance_after);
        $this->assertSame('2026-10-23', $txn->created_at->toDateString(), 'dated on the legacy date');
        $fresh = $oa->fresh();
        $this->assertSame(DigiOrder::STATUS_CREDITED, $fresh->status);
        $this->assertSame($this->admin->id, $fresh->decided_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'pending_digi_credited']);

        // once sent it leaves the pending list and cannot be sent twice
        Livewire::test(PendingDigi::class)->assertCanNotSeeTableRecords([$fresh]);
        Livewire::test(PendingDigi::class)->filterTable('status', DigiOrder::STATUS_CREDITED)
            ->assertCanSeeTableRecords([$fresh])->assertTableActionHidden('send', $fresh);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\Digi\PendingDigiService::class)->credit($fresh, $this->admin->id);
    }

    public function test_bulk_send_credits_every_selected_pending_balance(): void
    {
        $a = $this->member('LJW811');
        $b = $this->member('LJW812');
        $oa = DigiOrder::create(['ref_no' => 'LEGACY-LJW811', 'member_id' => $a->id, 'source' => 'OPENING', 'status' => 'pending', 'gold_wt' => 1, 'value' => 10000, 'rate_on_date' => 10000, 'created_at' => '2026-10-01']);
        $ob = DigiOrder::create(['ref_no' => 'LEGACY-LJW812', 'member_id' => $b->id, 'source' => 'OPENING', 'status' => 'pending', 'gold_wt' => 3, 'value' => 36000, 'rate_on_date' => 12000, 'created_at' => '2026-10-02']);

        Livewire::test(PendingDigi::class)
            ->callTableBulkAction('bulkSend', [$oa, $ob])
            ->assertHasNoTableBulkActionErrors();

        $this->assertEquals(1.0, (float) MemberWallet::find($a->id)->digi_gold_grams);
        $this->assertEquals(3.0, (float) MemberWallet::find($b->id)->digi_gold_grams);
        $this->assertSame(2, DigiGoldTxn::where('source', 'legacy_opening')->count());
        $this->assertSame(0, DigiOrder::where('status', 'pending')->count());

        // dealers / staff cannot send
        $staff = User::create(['name' => 'Manager', 'email' => 'manager@test.local', 'password' => 'secret123', 'status' => 'active']);
        $staff->assignRole('manager');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($staff);
        $oc = DigiOrder::create(['ref_no' => 'LEGACY-X', 'member_id' => $a->id, 'source' => 'OPENING', 'status' => 'pending', 'gold_wt' => 1, 'value' => 1, 'rate_on_date' => 1, 'created_at' => '2026-10-03']);
        Livewire::test(PendingDigi::class)->assertTableActionHidden('send', $oc);
    }
}
