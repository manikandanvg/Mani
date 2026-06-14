<?php

namespace App\Services\Pan;

/**
 * Dev/test driver — validates format and "matches" the supplied name. Use a PAN
 * starting with "Z" to simulate a name mismatch when testing the threshold rule.
 */
class FakePanVerifier implements PanVerifier
{
    public function verify(string $pan, ?string $name = null, ?string $dob = null): array
    {
        $pan = strtoupper(trim($pan));
        $valid = (bool) preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan);

        if (! $valid) {
            return ['valid' => false, 'registered_name' => null, 'match' => 'none', 'score' => 0, 'message' => 'Invalid PAN format'];
        }

        // simulate a mismatch for PANs starting with Z
        if (str_starts_with($pan, 'Z')) {
            return ['valid' => true, 'registered_name' => 'SOMEONE ELSE', 'match' => 'none', 'score' => 0.2, 'message' => 'Name does not match PAN'];
        }

        return [
            'valid' => true,
            'registered_name' => $name ? strtoupper($name) : 'VERIFIED HOLDER',
            'match' => $name ? 'exact' : 'unknown',
            'score' => $name ? 1.0 : 0.0,
            'message' => 'PAN verified',
        ];
    }
}
