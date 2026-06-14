<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberContract extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function bond() { return $this->belongsTo(Bond::class); }
    public function member() { return $this->belongsTo(Member::class); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
