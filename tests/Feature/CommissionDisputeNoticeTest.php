<?php

namespace Tests\Feature;

use App\Filament\Resources\CommissionLedgerResource\Pages\ListCommissionLedgers;
use App\Models\CommissionEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-10-31 (10): Commission ledger → "Dispute notice" crashed with a server
 * error. The action must send the push/inbox notice and leave the ledger untouched.
 */
class CommissionDisputeNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispute_notice_action_sends_notification(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $member = Member::firstOrFail();

        // dated today: the ledger screen opens on the last 7 days, and a mounted table action
        // resolves its record through that filtered query
        $ledger = CommissionLedger::create([
            'type' => 'IC', 'member_id' => $member->id, 'amount' => 150, 'status' => 'pending',
            'earned_on' => now()->toDateString(), 'invoice_no' => 'INV-TEST-1',
        ]);
        $entry = CommissionEntry::where('source', 'ledger')->where('source_id', $ledger->id)->firstOrFail();

        $this->actingAs($admin);
        $this->get('/admin/login');

        Livewire::test(ListCommissionLedgers::class)
            ->callTableAction('dispute', $entry, data: ['note' => 'Bill under verification.'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Dispute notification sent');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $member->getMorphClass(),
            'notifiable_id' => $member->id,
        ]);
        $this->assertSame('pending', $ledger->fresh()->status);
    }
}
