<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriveShare extends Model
{
    public function file() { return $this->belongsTo(DriveFile::class, 'file_id'); }
    public function folder() { return $this->belongsTo(DriveFolder::class, 'folder_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
