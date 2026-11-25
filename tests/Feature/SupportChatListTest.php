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

    /** User 2026-11-20: the list is searchable by the person's name, phone number and distributor ID. */
    public function test_the_list_is_searchable_by_name_phone_and_id(): void
    {
        $a = $this->thread('LJW0099001', 'Wallet balance is missing');
        $b = $this->thread('LJW0099002', 'Withdrawal is pending');

        foreach (['9000099001', 'Person LJW0099001', 'LJW0099001', 'person 99001'] as $search) {
            Livewire::test(ListSupportThreads::class)->searchTable($search)
                ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        }
        Livewire::test(ListSupportThreads::class)->searchTable('Withdrawal')          // subject still works
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
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

    /** User 2026-11-20: one customer's repeated open chats become one chat, messages in written order. */
    public function test_duplicate_open_chats_of_one_customer_are_merged(): void
    {
        $svc = app(SupportService::class);
        $a = $this->thread('LJW0099001', 'Wallet balance is missing');
        $member = $a->owner;
        $svc->supportReply($a, 'We are checking.', null);                    // answered → not waiting for us
        $b = $svc->userMessage(null, $member, 'Wallet still missing, please reply')->thread;
        $old = $svc->userMessage(null, $member, 'Old closed matter')->thread;
        $old->update(['status' => 'closed']);
        $other = $this->thread('LJW0099002', 'Withdrawal is pending');
        $svc->supportReply($b, 'Noted.', null);
        $b->forceFill(['needs_staff' => true])->save();
        $this->assertSame("/support/{$b->id}", $member->notifications()->latest('created_at')->get()->firstWhere('data.data.thread_id', $b->id)->data['route']);

        Livewire::test(ListSupportThreads::class)->callAction('merge_duplicates', data: ['with_closed' => false]);

        $this->assertNull(SupportThread::find($b->id));
        $a = $a->fresh();
        $this->assertSame(['Wallet balance is missing', 'We are checking.', 'Wallet still missing, please reply', 'Noted.'],
            $a->messages()->orderBy('id')->pluck('body')->all());
        $this->assertSame('Wallet balance is missing | Wallet still missing, please reply', $a->subject);
        $this->assertTrue($a->needs_staff);
        $this->assertSame('closed', $old->fresh()->status);                   // closed chats and other people are left alone
        $this->assertSame(1, $other->fresh()->messages()->count());
        $this->assertSame(0, $member->notifications()->get()->filter(fn ($n) => ($n->data['data']['thread_id'] ?? 0) === $b->id)->count());
        $this->assertSame(2, $member->notifications()->get()->filter(fn ($n) => $n->data['route'] === "/support/{$a->id}")->count());

        // and a "new" chat from the app while one is open lands in that chat
        \Laravel\Sanctum\Sanctum::actingAs($member, ['*']);
        $this->postJson('/api/v1/support/threads', ['subject' => 'Again', 'body' => 'Any update?'])
            ->assertCreated()->assertJsonPath('data.id', $a->id);
        $this->assertSame("Again\nAny update?", $a->messages()->latest('id')->value('body'));
        $this->artisan('support:merge-duplicates')->expectsOutputToContain('0 customer(s)')->assertSuccessful();
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

    /** Board 2026-11-25: "merge not working properly" — closed chats were left out, and an old chat came back as a second open one. */
    public function test_closed_chats_are_merged_too_and_a_reopened_chat_never_makes_a_second_open_one(): void
    {
        $member = \App\Models\Member::firstOrFail();
        $support = app(SupportService::class);
        $old = $support->userMessage(null, $member, 'October wallet missing')->thread;
        $support->supportReply($old, 'Credited', null);
        $old->update(['status' => 'closed']);
        $older = $support->userMessage(null, $member, 'QR not received')->thread;
        $older->update(['status' => 'closed', 'needs_staff' => true]);
        $open = $support->userMessage(null, $member, 'November wallet missing')->thread;
        $open->update(['needs_staff' => false]);
        $this->assertSame(1, SupportThread::where('status', 'open')->count());

        // the plain merge has nothing to do — one open chat — yet the person is three lines
        $this->assertSame(0, $support->duplicateGroups()->count());
        $this->assertSame(1, $support->duplicateGroups(true)->count());

        Livewire::test(ListSupportThreads::class)->callAction('merge_duplicates', data: ['with_closed' => true])->assertHasNoActionErrors();
        // one chat: the OPEN one stays (the app keeps showing it), the history is inside, in order
        $this->assertSame([$open->id], SupportThread::pluck('id')->all());
        $open->refresh();
        $this->assertSame('open', $open->status);
        $this->assertFalse((bool) $open->needs_staff, 'an unanswered CLOSED chat does not put it back in the queue');
        $this->assertSame(['October wallet missing', 'Credited', 'QR not received', 'November wallet missing'], $open->messages()->orderBy('id')->pluck('body')->all());

        // a customer with only closed chats keeps ONE closed chat
        $other = \App\Models\Member::where('id', '!=', $member->id)->first() ?? \App\Models\Member::create(['member_code' => 'LJWCL2', 'name' => 'Closed Two',
            'phone' => '9555555555', 'status' => 'active', 'joined_on' => '2026-10-01', 'rank_id' => $member->rank_id]);
        $c1 = $support->userMessage(null, $other, 'First')->thread;
        $c1->update(['status' => 'closed']);
        $c2 = $support->userMessage(null, $other, 'Second')->thread;
        $c2->update(['status' => 'closed']);
        $this->artisan('support:merge-duplicates', ['--apply' => true, '--with-closed' => true])->assertSuccessful();
        $this->assertSame(1, SupportThread::where('owner_id', $other->id)->count());
        $this->assertSame('closed', SupportThread::where('owner_id', $other->id)->value('status'));

        // the user answers in an OLD closed chat while another chat is open → still one open chat,
        // and it is the chat they are writing in
        $c = SupportThread::where('owner_id', $other->id)->firstOrFail();
        $new = $support->userMessage(null, $other, 'A new question')->thread;
        $this->assertSame(1, SupportThread::where('owner_id', $other->id)->where('status', 'open')->count());
        $support->userMessage($c, $other, 'Following up on the old one');
        $this->assertSame([$c->id], SupportThread::where('owner_id', $other->id)->where('status', 'open')->pluck('id')->all());
        $this->assertNull(SupportThread::find($new->id));
        $this->assertContains('A new question', $c->messages()->pluck('body')->all());

        // the desk reopening an old chat beside an open one folds them as well
        $shut = $support->userMessage(null, $member, 'An older matter')->thread;
        $shut->update(['status' => 'closed']);
        $this->assertSame(1, SupportThread::where('owner_id', $member->id)->where('status', 'open')->count());
        Livewire::test(ListSupportThreads::class)->filterTable('status', 'closed')->callTableAction('toggle', $shut);
        $this->assertSame([$shut->id], SupportThread::where('owner_id', $member->id)->pluck('id')->all());
        $this->assertSame('open', $shut->fresh()->status);
    }
}
