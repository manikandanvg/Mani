<?php

namespace App\Services\Support;

use App\Models\Customer;
use App\Models\Member;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Notifications\AppNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Support chat domain logic (Phase 4c), shared by the app API (SupportController),
 * the HQ Filament reply action, and the inbound-WhatsApp webhook. Keeps thread
 * bookkeeping (last_message_at, status, read cursors) and the reply→notify wiring
 * in one place so every entry point behaves identically.
 */
class SupportService
{
    /**
     * Append a message from the app user, opening a thread if none is given.
     *
     * @param  Model  $owner   the Member or Customer
     */
    public function userMessage(?SupportThread $thread, Model $owner, string $body, ?string $subject = null, string $source = 'app'): SupportMessage
    {
        $thread ??= new SupportThread([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'subject' => $subject ?: Str::limit($body, 60),
        ]);
        $reused = $thread->exists && $thread->status === 'closed';   // writing into an old closed chat reopens it
        if (! $thread->exists) {
            $thread->save();
        }

        $message = $thread->messages()->create([
            'sender' => 'user',
            'body' => $body,
            'source' => $source,
        ]);

        $thread->forceFill([
            // A new message reopens a closed chat — except a bare "ok, thank you", which is the
            // usual answer to "resolved" and used to bring every closed chat back into the list.
            'status' => $thread->status === 'closed' && SupportBot::isThanks($body) ? 'closed' : 'open',
            'last_message_at' => $message->created_at,
            // The user just spoke; their own view is current, HQ now has unread.
            'owner_last_read_at' => $message->created_at,
            // triage tag for the desk (filter + bulk reply by topic) — set once, from the first words
            'topic' => $thread->topic ?: SupportBot::suggestFor($thread),
            // The desk's queue: this chat needs a STAFF answer. A bare "ok, thank you" does not
            // put an already-answered chat back in the queue; an auto reply never takes one out.
            'needs_staff' => $thread->needs_staff || ! SupportBot::isThanks($body),
        ])->save();

        // One person, one open chat (board 2026-11-25): a reply written into an OLD closed chat
        // reopens it — any other open chat of the same person is folded into this one.
        if ($reused) {
            $this->consolidate($thread);
        }

        // Auto reply (2026-11-18, off unless SUPPORT_BOT_AUTO_REPLY=true): a fixed acknowledgement
        // of the first message and short fixed answers to follow-ups (SupportBot::respond), so
        // nobody waits days for a first answer. Never allowed to break the message.
        if (SupportBot::enabled()) {
            try {
                app(SupportBot::class)->respond($thread, $message);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Support auto reply failed: ' . $e->getMessage());
            }
        }

        return $message;
    }

    /** Append an HQ reply and notify the thread owner (inbox + push). */
    /**
     * $html (board 2026-11-15): the desk's rich-text version of the reply, shown in the panel.
     * $body stays the plain text — what the app and the push notification show.
     */
    public function supportReply(SupportThread $thread, string $body, ?int $supportUserId = null, ?string $html = null, string $source = 'app'): SupportMessage
    {
        // the chat was folded into the person's other chat a moment ago (bulk reply): answer there
        if (! SupportThread::whereKey($thread->getKey())->exists()) {
            $thread = SupportThread::where('owner_type', $thread->owner_type)->where('owner_id', $thread->owner_id)
                ->orderByRaw("status = 'open' desc")->latest('last_message_at')->latest('id')->firstOrFail();
        }

        $wasClosed = $thread->status === 'closed';
        $message = $thread->messages()->create([
            'sender' => 'support',
            'support_user_id' => $supportUserId,
            'body' => $body,
            'body_html' => filled($html) ? $html : null,
            'source' => $source,   // 'bot' = the auto reply (SupportBot)
        ]);

        $thread->forceFill([
            // a person's reply (re)opens the chat; the auto reply leaves a closed chat closed
            'status' => $source === SupportBot::SOURCE && $thread->status === 'closed' ? 'closed' : 'open',
            'last_message_at' => $message->created_at,
            'support_last_read_at' => $message->created_at,
            // only a person's reply takes the chat out of the desk's queue — never the bot's
            'needs_staff' => $source === SupportBot::SOURCE ? (bool) $thread->needs_staff : false,
        ])->save();

        if ($wasClosed) {
            $this->consolidate($thread);   // a reply that reopened an old chat: still one open chat per person
        }

        $thread->owner?->notify(new AppNotification(
            category: 'support',
            title: 'Support replied',
            body: Str::limit($body, 120),
            route: "/support/{$thread->id}",
            data: ['thread_id' => $thread->id],
        ));

        return $message;
    }

    /**
     * Route an inbound message (e.g. WhatsApp) from a phone number into its owner's
     * support thread — reusing the latest open thread or opening a new one. Returns
     * null when no Member/Customer matches the number.
     */
    public function inboundFromPhone(string $phone, string $body, string $source = 'whatsapp'): ?SupportMessage
    {
        $owner = $this->ownerByPhone($phone);
        if ($owner === null) {
            return null;
        }

        $thread = SupportThread::where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('status', 'open')
            ->latest('last_message_at')
            ->first();

        $message = $this->userMessage($thread, $owner, $body, source: $source);
        // The user just messaged via another channel — they haven't read in-app replies.
        $message->thread->forceFill(['owner_last_read_at' => null])->save();

        return $message;
    }

    /** Find the Member or Customer that owns a phone number (country-code tolerant). */
    public function ownerByPhone(string $phone): ?Model
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        $last10 = substr($digits, -10);
        $candidates = array_values(array_unique(array_filter([
            $digits,
            $last10,
            str_starts_with($digits, '91') ? substr($digits, 2) : null,
        ])));

        return Member::whereIn('phone', $candidates)->first()
            ?? Customer::whereIn('phone', $candidates)->first();
    }

    /** The chat a new message of this person belongs in: their latest OPEN one, if any. */
    public function openThreadOf(Model $owner): ?SupportThread
    {
        return SupportThread::where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey())
            ->where('status', 'open')->latest('last_message_at')->latest('id')->first();
    }

    /**
     * Owners (one person = one name + phone) holding more than one OPEN chat, with those chats
     * oldest first. User 2026-11-20: unanswered for a week, people raised the same thing again and
     * again, each time as a new chat.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, SupportThread>>
     */
    public function duplicateGroups(bool $withClosed = false): \Illuminate\Support\Collection
    {
        return SupportThread::when(! $withClosed, fn ($q) => $q->where('status', 'open'))->orderBy('id')->get()
            ->groupBy(fn (SupportThread $t) => $t->owner_type . '#' . $t->owner_id)
            ->filter(fn ($g) => $g->count() > 1)->values();
    }

    /**
     * Keep ONE open chat per person (board 2026-11-25 — "merge not working properly": merged
     * people came back as two chats). Chats multiply again whenever an old CLOSED chat is
     * reopened — the user answers in it, the desk replies in it or presses Reopen — while the
     * person already has another open chat. Called on each of those: the other open chats of the
     * same person are folded into $thread, the chat in use, so nobody's screen loses its chat.
     */
    public function consolidate(SupportThread $thread): int
    {
        if ($thread->status !== 'open' || ! $thread->owner_id) {
            return 0;
        }
        $open = SupportThread::where('owner_type', $thread->owner_type)->where('owner_id', $thread->owner_id)
            ->where('status', 'open')->orderBy('id')->get();
        if ($open->count() < 2) {
            return 0;
        }
        $removed = $this->mergeThreads($open, $thread);
        $thread->refresh();

        return $removed;
    }

    /**
     * Fold a person's open chats into ONE: every message moves into their first chat (message ids
     * are global, so the transcript reads in the order things were written), the other chats are
     * removed and their inbox notifications point at the chat that is kept. Nothing is sent.
     *
     * @param  \Illuminate\Support\Collection<int, SupportThread>  $threads  the same owner's chats
     * $keep = the chat that stays (default: the person's first). A set of only CLOSED chats stays
     * closed; as soon as one of them is open the merged chat is open.
     *
     * @return int chats removed
     */
    public function mergeThreads(\Illuminate\Support\Collection $threads, ?SupportThread $keep = null): int
    {
        $threads = $threads->sortBy('id')->values();
        $keep = $keep ? ($threads->firstWhere('id', $keep->id) ?? $threads->first()) : $threads->first();
        $threads = $threads->reject(fn (SupportThread $t) => $t->id === $keep->id)->prepend($keep)->values();
        $rest = $threads->slice(1)->filter(fn (SupportThread $t) => $t->owner_type === $keep->owner_type && (int) $t->owner_id === (int) $keep->owner_id);
        if ($rest->isEmpty()) {
            return 0;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($keep, $rest) {
            $ids = $rest->pluck('id')->all();
            SupportMessage::whereIn('thread_id', $ids)->update(['thread_id' => $keep->id]);

            $all = $rest->push($keep);
            $subjects = $all->sortBy('id')->pluck('subject')->filter()->unique()->values();
            $keep->forceFill([
                'status' => $all->contains(fn (SupportThread $t) => $t->status === 'open') ? 'open' : 'closed',
                'subject' => Str::limit($subjects->implode(' | '), 250, '…'),
                'topic' => $keep->topic ?: $all->pluck('topic')->filter()->first(),
                // waiting for the desk only if a chat that is still OPEN was waiting — an old closed
                // chat nobody answered must not put the merged chat back in the queue
                'needs_staff' => $all->contains(fn (SupportThread $t) => $t->status === 'open' && (bool) $t->needs_staff),
                'last_message_at' => $all->max('last_message_at'),
                'owner_last_read_at' => $all->max('owner_last_read_at'),
                'support_last_read_at' => $all->max('support_last_read_at'),
            ])->save();

            // "Support replied" rows in the app inbox open /support/{id} — keep them working
            \Illuminate\Support\Facades\DB::table('notifications')
                ->where('notifiable_type', $keep->owner_type)->where('notifiable_id', $keep->owner_id)
                ->where('data', 'like', '%thread_id%')->orderBy('created_at')->get(['id', 'data'])
                ->each(function ($n) use ($ids, $keep) {
                    $data = json_decode((string) $n->data, true);
                    if (in_array((int) ($data['data']['thread_id'] ?? 0), $ids, true)) {
                        $data['data']['thread_id'] = $keep->id;
                        $data['route'] = "/support/{$keep->id}";
                        \Illuminate\Support\Facades\DB::table('notifications')->where('id', $n->id)->update(['data' => json_encode($data)]);
                    }
                });

            SupportThread::whereIn('id', $ids)->delete();

            return count($ids);
        });
    }

    /**
     * Merge every person's duplicate chats — the open ones, and with $withClosed (board
     * 2026-11-25) the closed ones too, so one person is ONE line in the open list and in the
     * closed list, with the whole history inside. @return array{people:int, removed:int}
     */
    public function mergeAllDuplicates(bool $withClosed = false): array
    {
        $groups = $this->duplicateGroups($withClosed);
        // with closed chats in the set, the chat that stays is the person's OPEN one (the one their
        // app shows now); among open chats alone it is the first, as before
        $removed = $groups->sum(fn ($g) => $this->mergeThreads($g, $withClosed ? $g->firstWhere('status', 'open') : null));
        if ($removed) {
            \App\Services\Activity\ActivityLogger::log('support', 'support_chats_merged',
                "{$removed} duplicate support chat(s) of {$groups->count()} people merged into one chat each",
                ['details' => ['people' => $groups->count(), 'removed' => $removed]]);
        }

        return ['people' => $groups->count(), 'removed' => $removed];
    }

    public function markOwnerRead(SupportThread $thread): void
    {
        $thread->forceFill(['owner_last_read_at' => now()])->save();
    }

    public function markSupportRead(SupportThread $thread): void
    {
        $thread->forceFill(['support_last_read_at' => now()])->save();
    }
}
