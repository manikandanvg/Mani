<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Rank;
use App\Models\SupportThread;
use App\Services\Support\SupportBot;
use App\Services\Support\SupportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-11-18 — Support Chat ready replies + auto-reply bot: fixed texts, picked by what the user
 * wrote, one acknowledgement per chat, off unless switched on, and never any member data in it.
 */
class SupportBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function member(): Member
    {
        return Member::create(['member_code' => 'LJW0099001', 'name' => 'Private Person', 'phone' => '9000012345',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'), 'status' => 'active']);
    }

    public function test_a_message_is_tagged_with_its_topic(): void
    {
        $this->assertSame('withdrawal', SupportBot::topicOf('My withdrawal request of last week is still pending'));
        $this->assertSame('incentive', SupportBot::topicOf('October incentive amount not credited'));
        $this->assertSame('wallet', SupportBot::topicOf('Digi gold balance is missing after the software was migrated'));
        $this->assertSame('login', SupportBot::topicOf('I cannot login, missed call is not working'));
        $this->assertSame('general', SupportBot::topicOf('Hello sir'));

        $msg = app(SupportService::class)->userMessage(null, $this->member(), 'Kindly check my wallet balance');
        $this->assertSame('wallet', $msg->thread->fresh()->topic);
    }

    public function test_the_bot_is_off_by_default_and_says_nothing(): void
    {
        $msg = app(SupportService::class)->userMessage(null, $this->member(), 'Kindly check my wallet balance');

        $this->assertFalse(SupportBot::enabled());
        $this->assertSame(0, $msg->thread->messages()->where('sender', 'support')->count());
    }

    public function test_when_on_it_acknowledges_once_with_fixed_text_and_no_member_data(): void
    {
        config(['support_replies.bot.auto_reply' => true]);
        $member = $this->member();
        $svc = app(SupportService::class);

        $first = $svc->userMessage(null, $member, 'My October incentive is not credited');
        $thread = $first->thread;
        $svc->userMessage($thread, $member, 'Please check soon');          // a follow-up: "details received", not the acknowledgement again

        $replies = $thread->messages()->where('sender', 'support')->orderBy('id')->get();
        $this->assertCount(2, $replies);
        $this->assertSame(SupportBot::body('received'), $replies[1]->body);
        $this->assertSame(SupportBot::SOURCE, $replies[0]->source);
        $this->assertNull($replies[0]->support_user_id);
        $this->assertSame(SupportBot::body('incentive'), $replies[0]->body);
        // nothing about the member can be in a reply
        foreach (['Private Person', '9000012345', 'LJW0099001'] as $private) {
            $this->assertStringNotContainsString($private, $replies[0]->body);
        }
        // the bot wrote its message and nothing else
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_it_answers_follow_ups_with_fixed_texts_and_stops_at_the_limit(): void
    {
        config(['support_replies.bot.auto_reply' => true]);
        $member = $this->member();
        $svc = app(SupportService::class);
        $botBodies = fn (SupportThread $t) => $t->messages()->where('source', SupportBot::SOURCE)->orderBy('id')->pluck('body')->all();

        $thread = $svc->userMessage(null, $member, 'Wallet balance is missing')->thread;       // 1: wallet acknowledgement
        $svc->userMessage($thread, $member, 'Amount 5000 for October');                          // 2: details received
        $this->assertSame([SupportBot::body('wallet'), SupportBot::body('received')], $botBodies($thread));
        $this->assertTrue($thread->fresh()->needs_staff, 'an auto reply never takes a chat out of the staff queue');

        $svc->userMessage($thread, $member, 'ok thank you sir');                                 // 3: you are welcome
        $this->assertSame(SupportBot::body('thanks'), last($botBodies($thread)));

        $svc->userMessage($thread, $member, 'Also my withdrawal is pending');                    // limit of 3 reached: silence
        $this->assertCount(3, $botBodies($thread));
        $this->assertTrue($thread->fresh()->needs_staff);
    }

    public function test_a_new_topic_in_the_same_chat_is_acknowledged_once(): void
    {
        config(['support_replies.bot.auto_reply' => true]);
        $member = $this->member();
        $svc = app(SupportService::class);

        $thread = $svc->userMessage(null, $member, 'Wallet balance is missing')->thread;
        $svc->userMessage($thread, $member, 'Also my withdrawal request is pending');
        $svc->userMessage($thread, $member, 'withdrawal still pending');                         // same topic again: not repeated

        $bodies = $thread->messages()->where('source', SupportBot::SOURCE)->orderBy('id')->pluck('body')->all();
        $this->assertSame(SupportBot::body('wallet'), $bodies[0]);
        $this->assertSame(SupportBot::body('withdrawal'), $bodies[1]);
        $this->assertSame(1, collect($bodies)->filter(fn ($b) => $b === SupportBot::body('withdrawal'))->count());
    }

    public function test_the_staff_queue_follows_the_staff_not_the_bot(): void
    {
        $member = $this->member();
        $svc = app(SupportService::class);

        $thread = $svc->userMessage(null, $member, 'Wallet balance is missing')->thread;
        $this->assertTrue($thread->fresh()->needs_staff);

        $svc->supportReply($thread, 'Checked and corrected.', null);                             // a person answered
        $this->assertFalse($thread->fresh()->needs_staff);

        $svc->userMessage($thread->fresh(), $member, 'ok thanks');                               // a bare thank-you: stays out
        $this->assertFalse($thread->fresh()->needs_staff);

        $svc->userMessage($thread->fresh(), $member, 'But the amount is still wrong');           // a real follow-up: back in
        $this->assertTrue($thread->fresh()->needs_staff);
    }

    public function test_the_bot_never_answers_a_chat_the_desk_already_answered(): void
    {
        $member = $this->member();
        $svc = app(SupportService::class);
        $thread = $svc->userMessage(null, $member, 'Wallet amount missing')->thread;
        $svc->supportReply($thread, 'We are checking.', null);

        $this->assertNull(app(SupportBot::class)->acknowledge($thread));
        $this->assertSame(1, $thread->messages()->where('sender', 'support')->count());
    }

    public function test_every_ready_reply_is_free_of_placeholders_and_digits(): void
    {
        foreach (config('support_replies.templates') as $key => $t) {
            $this->assertNotEmpty($t['body'], $key);
            $this->assertDoesNotMatchRegularExpression('/[{}\[\]<>]|\d{4,}/', $t['body'], "{$key} must be fixed text");
            // every reply has its Tamil version, in Tamil script and equally fixed
            $ta = (string) config("support_replies.tamil.{$key}");
            $this->assertTrue(SupportBot::isTamil($ta), "{$key} has no Tamil version");
            $this->assertDoesNotMatchRegularExpression('/[{}\[\]<>]|\d{4,}/', $ta, "{$key} (Tamil) must be fixed text");
        }
    }

    public function test_a_message_for_many_chats_cannot_carry_a_personal_detail(): void
    {
        $this->assertSame('LJW0017555', SupportBot::personalDetailIn('Your ID LJW0017555 is corrected'));
        $this->assertSame('9000012345', SupportBot::personalDetailIn('Call 9000012345 for this'));
        $this->assertSame('25000', SupportBot::personalDetailIn('Your amount of Rs. 25,000 is credited'));
        $this->assertNull(SupportBot::personalDetailIn('Payments will be made by 20-11-2026 at your branch.'));
        foreach (config('support_replies.templates') as $key => $t) {
            $this->assertNull(SupportBot::personalDetailIn($t['body']), "{$key} must pass the bulk-reply check");
            $this->assertNull(SupportBot::personalDetailIn((string) config("support_replies.tamil.{$key}")), "{$key} (Tamil)");
        }
    }

    public function test_a_user_who_writes_in_tamil_is_answered_in_tamil(): void
    {
        config(['support_replies.bot.auto_reply' => true]);
        $svc = app(SupportService::class);

        $tamil = $svc->userMessage(null, $this->member(), 'என் வாலட் தொகை வரவில்லை')->thread;
        $reply = $tamil->messages()->where('sender', 'support')->first();
        $this->assertSame(config('support_replies.tamil.wallet'), $reply->body);
        $this->assertSame('wallet' . SupportBot::TAMIL, SupportBot::suggestReplyFor($tamil));

        // the desk can pick either language for any reply
        $this->assertArrayHasKey('resolved', SupportBot::options());
        $this->assertArrayHasKey('resolved' . SupportBot::TAMIL, SupportBot::options());
        $this->assertSame(SupportBot::body('wallet'), SupportBot::body('wallet'));
        $this->assertNotSame(SupportBot::body('wallet'), SupportBot::body('wallet' . SupportBot::TAMIL));
    }
}
