<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'support';

    protected $guarded = [];

    protected $casts = [
        'is_read' => 'boolean',
    ];
}
