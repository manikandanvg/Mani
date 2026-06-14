<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $table = 'stock';

    protected $casts = [
        'quantity' => 'decimal:4',
        'last_rate' => 'decimal:2',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }
    public function movements()
    {
        return $this->hasMany(StockMovement::class, 'catalog_product_id', 'catalog_product_id')
            ->where('branch_id', $this->branch_id);
    }
}
