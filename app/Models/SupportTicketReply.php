<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reply on a support ticket's trail. */
class SupportTicketReply extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // Ticket-response acknowledgement (board 2026-08-11): a staff reply pushes
        // to the ticket's distributor and lands in their inbox. Model-level so every
        // creation path (panel, future API) notifies consistently.
        static::created(function (self $reply) {
            $ticket = $reply->ticket;
            if ($ticket?->member && $reply->user_id) {
                \App\Services\Push\Notifier::to($ticket->member, 'system',
                    'Reply on ticket ' . $ticket->ticket_no,
                    (string) str($reply->body)->limit(140),
                    route: '/tickets/' . $ticket->id,
                );
            }
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
