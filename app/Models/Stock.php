<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $table = 'stock';

    protected $casts = [
        'quantity' => 'decimal:4',
        'min_qty' => 'decimal:4',
        'last_rate' => 'decimal:2',
    ];

    /**
     * Low stock (board 2026-08-13): quantity has fallen to or below the branch's
     * opening level for this product. NULL min_qty = no opening level set, never low.
     */
    public function getIsLowAttribute(): bool
    {
        return $this->min_qty !== null && (float) $this->quantity <= (float) $this->min_qty;
    }

    /** Rows at or below their opening level — drives the highlight, filter and alert. */
    public function scopeLow($query)
    {
        return $query->whereNotNull('min_qty')->whereColumn('quantity', '<=', 'min_qty');
    }

    public function branch() { return $this->belongsTo(Branch::class); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }

    /** Customized-order piece this row carries (order_line_id 0 = ordinary stock). */
    public function orderLine() { return $this->belongsTo(BranchOrderLine::class, 'order_line_id'); }

    public function isCustomPiece(): bool
    {
        return (int) $this->order_line_id > 0;
    }
    public function movements()
    {
        return $this->hasMany(StockMovement::class, 'catalog_product_id', 'catalog_product_id')
            ->where('branch_id', $this->branch_id);
    }
}
