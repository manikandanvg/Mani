<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RdEntry extends Model
{
    protected $casts = [
        'paid_on' => 'date',
        'value' => 'decimal:2',
        'due_count' => 'integer',
    ];

    public function bond()
    {
        return $this->belongsTo(Bond::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
