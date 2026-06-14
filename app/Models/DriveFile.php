<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriveFile extends Model
{
    use SoftDeletes;

    protected $casts = [
        'size' => 'integer',
    ];

    public function folder() { return $this->belongsTo(DriveFolder::class, 'folder_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function shares() { return $this->hasMany(DriveShare::class, 'file_id'); }
}
