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
        if (! $thread->exists) {
            $thread->save();
        }

        $message = $thread->messages()->create([
            'sender' => 'user',
            'body' => $body,
            'source' => $source,
        ]);

        $thread->forceFill([
            'status' => 'open',
            'last_message_at' => $message->created_at,
            // The user just spoke; their own view is current, HQ now has unread.
            'owner_last_read_at' => $message->created_at,
        ])->save();

        return $message;
    }

    /** Append an HQ reply and notify the thread owner (inbox + push). */
    public function supportReply(SupportThread $thread, string $body, ?int $supportUserId = null): SupportMessage
    {
        $message = $thread->messages()->create([
            'sender' => 'support',
            'support_user_id' => $supportUserId,
            'body' => $body,
            'source' => 'app',
        ]);

        $thread->forceFill([
            'status' => 'open',
            'last_message_at' => $message->created_at,
            'support_last_read_at' => $message->created_at,
        ])->save();

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

    public function markOwnerRead(SupportThread $thread): void
    {
        $thread->forceFill(['owner_last_read_at' => now()])->save();
    }

    public function markSupportRead(SupportThread $thread): void
    {
        $thread->forceFill(['support_last_read_at' => now()])->save();
    }
}
