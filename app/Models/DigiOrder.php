<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DigiOrder extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'digi';

    //
}
