<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One live row that belonged to a deleted member, kept as JSON (see DeletedMember). */
class DeletedMemberRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'purged' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function deletedMember()
    {
        return $this->belongsTo(DeletedMember::class);
    }
}
