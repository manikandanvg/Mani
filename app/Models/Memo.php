<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HQ memo (board phase 2, 2026-08-28 — Community → "Memo", formerly "Messages"):
 * a note broadcast to EVERY app-registered distributor as a push notification plus
 * an inbox entry the moment it is saved. sent_count = members reached.
 */
class Memo extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'meetings';

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'sent_count' => 'integer',
        'audience_ranks' => 'array',
        'audience_members' => 'array',
    ];

    /** Who a memo may be addressed to (user 2026-11-15). */
    public const AUDIENCES = [
        'all' => 'All distributors',
        'dealers' => 'Dealers only (distributors who have a branch login)',
        'ranks' => 'By rank (TBP stage)',
        'members' => 'Particular distributors',
    ];

    /**
     * The members this memo is for. Notifier then keeps the active ones who use the app.
     *   dealers  the distributor behind an active branch login (users.member_code, role distributor)
     *   ranks    members whose rank is one of the chosen stages — the same picker as a Zoom meeting
     *   members  exactly the distributors picked
     */
    public function audienceQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = Member::query();

        return match ($this->audience ?: 'all') {
            'dealers' => $q->whereIn('member_code', User::query()->where('status', 'active')->whereNotNull('branch_id')
                ->whereNotNull('member_code')->whereHas('roles', fn ($r) => $r->where('name', 'distributor'))->select('member_code')),
            'ranks' => $q->whereIn('rank_id', Rank::whereIn('depth', array_map('intval', (array) $this->audience_ranks))->select('id')),
            'members' => $q->whereIn('id', array_map('intval', (array) $this->audience_members)),
            default => $q,
        };
    }

    /** "Dealers only" / "Taluk Admin, State Admin" / "3 distributors" — for the list. */
    public function audienceLabel(): string
    {
        return match ($this->audience ?: 'all') {
            'dealers' => 'Dealers only',
            'ranks' => Rank::whereIn('depth', array_map('intval', (array) $this->audience_ranks))->orderBy('depth')->get()
                ->map(fn ($r) => \App\Support\Translatable::pick($r->name) ?: 'Rank ' . $r->depth)->implode(', ') ?: 'By rank',
            'members' => count((array) $this->audience_members) . ' distributor(s)',
            default => 'All distributors',
        };
    }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
