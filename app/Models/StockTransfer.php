<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single goods movement between two branches (seller → buyer). The transfer
 * margin earned by the seller on this hop is captured inline.
 */
class StockTransfer extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'stock';

    protected $guarded = [];

    protected $casts = [
        'transfer_date' => 'date',
        'weight' => 'decimal:4',
        'quantity' => 'decimal:3',
        'unit_rate' => 'decimal:2',
        'transfer_value' => 'decimal:2',
        'margin_pct' => 'decimal:3',
        'margin_amount' => 'decimal:2',
    ];

    public function sourceBranch() { return $this->belongsTo(Branch::class, 'source_branch_id'); }
    public function destinationBranch() { return $this->belongsTo(Branch::class, 'destination_branch_id'); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
