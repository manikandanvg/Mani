<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One step on a customized order's road map (board 2026-08-27): submitted → forwarded
 * (hop by hop up to HQ) → accepted → delivered (hop by hop back down) → billed, plus
 * rejections and the coin hand-over. Every branch on the road sees the full timeline.
 */
class BranchOrderEvent extends Model
{
    public const SUBMITTED = 'submitted';

    public const FORWARDED = 'forwarded';

    public const REJECTED = 'rejected';

    public const ACCEPTED = 'accepted';

    public const DELIVERED = 'delivered';

    public const COINS_CAPTURED = 'coins_captured';

    public const BILLED = 'billed';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public function order() { return $this->belongsTo(BranchOrderRequest::class, 'order_request_id'); }

    public function branch() { return $this->belongsTo(Branch::class); }

    public function toBranch() { return $this->belongsTo(Branch::class, 'to_branch_id'); }

    public function user() { return $this->belongsTo(User::class); }

    public function label(): string
    {
        return match ($this->action) {
            self::SUBMITTED => 'Order placed',
            self::FORWARDED => 'Forwarded up the chain',
            self::REJECTED => 'Rejected',
            self::ACCEPTED => 'Accepted by Head Office',
            self::DELIVERED => 'Goods sent down the chain',
            self::COINS_CAPTURED => 'Customer coins received at HQ',
            self::BILLED => 'Billed to customer (G10)',
            default => ucfirst(str_replace('_', ' ', (string) $this->action)),
        };
    }
}
