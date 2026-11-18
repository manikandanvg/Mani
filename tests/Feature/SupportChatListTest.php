<?php

namespace Tests\Feature;

use App\Filament\Resources\SupportThreadResource\Pages\ListSupportThreads;
use App\Livewire\SupportChatPanel;
use App\Models\Member;
use App\Models\Rank;
use App\Models\SupportThread;
use App\Models\User;
use App\Services\Support\SupportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Support Chat list: it opens on OPEN chats only, and a chat that is closed leaves the list —
 * whether it was closed from the row, in bulk, or inside the chat popup.
 */
class SupportChatListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
    }

    private function thread(string $code, string $text): SupportThread
    {
        $member = Member::create(['member_code' => $code, 'name' => 'Person ' . $code, 'phone' => '90000' . substr($code, -5),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'), 'status' => 'active']);

        return app(SupportService::class)->userMessage(null, $member, $text)->thread;
    }

    public function test_the_list_opens_on_open_chats_only(): void
    {
        $open = $this->thread('LJW0099001', 'Wallet balance is missing');
        $closed = $this->thread('LJW0099002', 'Withdrawal is pending');
        $closed->update(['status' => 'closed']);

        Livewire::test(ListSupportThreads::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed])
            ->filterTable('status', 'closed')
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$open]);
    }

    public function test_a_chat_closed_from_the_row_leaves_the_list(): void
    {
        $a = $this->thread('LJW0099001', 'Wallet balance is missing');
        $b = $this->thread('LJW0099002', 'Withdrawal is pending');

        Livewire::test(ListSupportThreads::class)
            ->callTableAction('toggle', $a)
            ->assertCanNotSeeTableRecords([$a])
            ->assertCanSeeTableRecords([$b]);
        $this->assertSame('closed', $a->fresh()->status);
    }

    public function test_a_chat_closed_inside_the_popup_tells_the_list(): void
    {
        $a = $this->thread('LJW0099001', 'Wallet balance is missing');

        Livewire::test(SupportChatPanel::class, ['threadId' => $a->id])
            ->call('toggleStatus')
            ->assertDispatched('support-thread-changed');
        $this->assertSame('closed', $a->fresh()->status);

        // the list, with that chat's popup open, hears it: popup shut, chat gone from "Open chats"
        $b = $this->thread('LJW0099002', 'Withdrawal is pending');
        $list = Livewire::test(ListSupportThreads::class)->mountTableAction('chat', $b);
        $b->update(['status' => 'closed']);
        $list->dispatch('support-thread-changed')
            ->assertSet('mountedTableActions', [])
            ->assertCanNotSeeTableRecords([$b]);
    }

    public function test_a_thank_you_does_not_bring_a_closed_chat_back(): void
    {
        config(['support_replies.bot.auto_reply' => true]);
        $a = $this->thread('LJW0099001', 'Wallet balance is missing');
        $svc = app(SupportService::class);
        $svc->supportReply($a, 'Checked and corrected.', null);
        $a->update(['status' => 'closed']);

        $svc->userMessage($a->fresh(), $a->owner, 'ok thank you sir');                 // + the bot's "you are welcome"
        $this->assertSame('closed', $a->fresh()->status);

        $svc->userMessage($a->fresh(), $a->owner, 'But the amount is still wrong');     // a real message reopens it
        $this->assertSame('open', $a->fresh()->status);
        $this->assertTrue($a->fresh()->needs_staff);
    }
}
