<?php

namespace App\Support;

class Features
{
    /** Monthly Tasks module (idle until the L-BOX launch — board 2026-10-31). */
    public static function monthlyTasks(): bool
    {
        return (bool) config('features.monthly_tasks', false);
    }
}
