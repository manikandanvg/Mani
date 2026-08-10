<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Support-desk ticket (board spec 2026-08-09) — a formal, assignable work item with
 * priority and a reply trail. Deliberately separate from SupportThread (live chat).
 * Sidebar surfaces this table twice: "Open Tickets" and "Closed Tickets".
 */
class SupportTicket extends Model
{
    protected $guarded = [];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public const CATEGORIES = [
        'general' => 'General',
        'billing' => 'Billing / Invoice',
        'scheme' => 'Scheme / Contract',
        'stock' => 'Stock / Order',
        'commission' => 'Commission / Wallet',
        'app' => 'Mobile App',
        'device' => 'L-BOX Device',
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            $ticket->ticket_no ??= self::nextTicketNo();
            $ticket->opened_by ??= auth()->id();
        });
    }

    /** TKT-000001 … — same running-serial style as stock returns (SRV-). */
    public static function nextTicketNo(): string
    {
        $last = (int) str_replace('TKT-', '', (string) static::query()->lockForUpdate()->max('ticket_no'));

        return sprintf('TKT-%06d', $last + 1);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class, 'ticket_id');
    }
}
