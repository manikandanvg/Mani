<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerCommission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'bill_date' => 'date',
        'paid_on' => 'date',
        'com_value' => 'decimal:2',
        'tds' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function referenceMember() { return $this->belongsTo(Member::class, 'reference_member_id'); }
}
