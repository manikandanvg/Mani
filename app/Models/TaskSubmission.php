<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Proof for a manual task (TOWN_VISIT, CUSTOM): text, photo, GPS — verified by HQ. */
class TaskSubmission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'value' => 'decimal:2',
        'verified_at' => 'datetime',
    ];

    public function assignment() { return $this->belongsTo(TaskAssignment::class, 'task_assignment_id'); }
    public function member() { return $this->belongsTo(Member::class); }
    public function verifier() { return $this->belongsTo(User::class, 'verified_by'); }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? url('admin/task-proof/' . $this->id) : null;
    }
}
