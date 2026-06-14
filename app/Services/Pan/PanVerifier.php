<?php

namespace App\Services\Pan;

/**
 * Provider-agnostic PAN verification. Swap drivers (Surepass / Sandbox / Zoop) via
 * config('services.pan.driver') without touching the Sales screen.
 *
 * verify() returns:
 *   [ 'valid' => bool, 'registered_name' => ?string,
 *     'match' => 'exact'|'partial'|'none'|'unknown', 'score' => float, 'message' => string ]
 */
interface PanVerifier
{
    public function verify(string $pan, ?string $name = null, ?string $dob = null): array;
}
