<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A distributor's request to re-point their supply source. Approving it updates the
 * branch's source_branch_id.
 */
class BranchSourceChangeRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function currentSource() { return $this->belongsTo(Branch::class, 'current_source_branch_id'); }
    public function requestedSource() { return $this->belongsTo(Branch::class, 'requested_source_branch_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by'); }
}
