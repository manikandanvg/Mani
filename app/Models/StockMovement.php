<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $casts = [
        'qty_change' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'moved_on' => 'date',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
