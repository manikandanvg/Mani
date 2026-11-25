<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Settlement of a contract CLOSED by a force delete (board 2026-11-25):
 *
 *   paid in on the contract + bonus due − already given to the member = balance payable
 *
 * One row per contract of the deleted member. The member's live rows are gone, so the row
 * carries its own copy of the member / contract / scheme names. `payable` rows wait for Head
 * Office to record the payment (status paid); `nil` = nothing is owed to the member.
 */
class ClosureSettlement extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'contract';

    public const STATUSES = ['payable' => 'Waiting for settlement', 'paid' => 'Settled'];

    protected $guarded = [];

    protected $casts = [
        'contract_date' => 'date',
        'closed_on' => 'date',
        'paid_on' => 'date',
        'contributed' => 'decimal:2',
        'bonus' => 'decimal:2',
        'given' => 'decimal:2',
        'balance' => 'decimal:2',
        'payable' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'detail' => 'array',
    ];

    public function contract() { return $this->belongsTo(MemberContract::class, 'member_contract_id'); }

    public function branch() { return $this->belongsTo(Branch::class)->withTrashed(); }

    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }
}
