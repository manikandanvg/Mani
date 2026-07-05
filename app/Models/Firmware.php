<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One uploaded L-BOX firmware binary (per board type). At most one row per board
 * is active; devices compare their running version against it on ota/check.
 */
class Firmware extends Model
{
    protected $table = 'firmwares';   // Eloquent treats "firmware" as uncountable

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
