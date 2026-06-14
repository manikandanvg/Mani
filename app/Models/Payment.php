<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function order() { return $this->belongsTo(Order::class); }
}
