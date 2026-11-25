<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stock moved by a "Force Close(Old)" (board 2026-11-25): what the closed bill had sold and the
 * cash stock of its Gold Saving renewals go to HEAD OFFICE — never back to the branch — and what
 * was already paid out / redeemed is charged to the issuing branch's cash stock. One row per
 * movement; the row keeps its own copy of the member's name, because the member may be deleted.
 */
class ForceCloseStock extends Model
{
    protected $table = 'force_close_stock';

    /** stock_movements.ref_type of the entries that ADD the recovered stock at Head Office. */
    public const MOVEMENT_REF = 'force_close';

    public const KINDS = [
        'bill_stock' => 'Bill stock → Head Office',
        'renewal' => 'Renewal cash stock → Head Office',
        'charge' => 'Charged to the branch (paid out / redeemed)',
    ];

    protected $guarded = [];

    protected $casts = ['moved_on' => 'date', 'quantity' => 'decimal:4'];

    public function fromBranch() { return $this->belongsTo(Branch::class, 'from_branch_id'); }
    public function toBranch() { return $this->belongsTo(Branch::class, 'to_branch_id'); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class)->withTrashed(); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    public static function record(string $kind, ?int $fromBranch, ?int $toBranch, int $productId, float $quantity, ?string $bill,
        ?Member $member, ?int $movementId, ?int $userId, ?string $note = null): self
    {
        return static::create([
            'moved_on' => Carbon::now()->toDateString(), 'kind' => $kind,
            'from_branch_id' => $fromBranch, 'to_branch_id' => $toBranch,
            'catalog_product_id' => $productId, 'quantity' => $quantity, 'invoice_no' => $bill,
            'member_id' => $member?->id, 'member_code' => $member?->member_code, 'member_name' => $member?->name,
            'note' => $note, 'stock_movement_id' => $movementId, 'created_by' => $userId,
        ]);
    }
}
