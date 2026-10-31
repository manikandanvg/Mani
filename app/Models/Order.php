<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'orders';

    protected $casts = [
        'fx_rate' => 'decimal:8',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function items() { return $this->hasMany(OrderItem::class); }
    public function payments() { return $this->hasMany(Payment::class); }
}
