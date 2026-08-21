<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One post office of the Indian pincode master (see migration 2026_08_21_100001).
 * Rows come from the bulk CSV import or are cached from the live India Post API.
 */
class Pincode extends Model
{
    protected $guarded = [];
}
