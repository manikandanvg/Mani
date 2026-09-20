<?php

namespace App\Support\BusinessModel;

use Illuminate\Support\Carbon;

/** One rule for "closed as on a date", shared by both sources and the page. */
class ContractStatus
{
    public static function isClosed(string $status, ?Carbon $settledOn, ?Carbon $endDate, Carbon $asOf): bool
    {
        if ($status !== 'active' && ($settledOn === null || $settledOn->lte($asOf))) {
            return true;
        }

        return $endDate !== null && $endDate->lt($asOf->copy()->startOfDay());
    }
}
