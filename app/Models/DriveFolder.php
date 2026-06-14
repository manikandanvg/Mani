<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriveFolder extends Model
{
    use SoftDeletes;

    public function parent() { return $this->belongsTo(DriveFolder::class, 'parent_id'); }
    public function children() { return $this->hasMany(DriveFolder::class, 'parent_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function files() { return $this->hasMany(DriveFile::class, 'folder_id'); }
}
