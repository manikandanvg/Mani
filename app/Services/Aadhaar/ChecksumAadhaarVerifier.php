<?php

namespace App\Services\Aadhaar;

/**
 * Instant, offline Aadhaar number validation: 12 digits, first digit 2-9, and a valid
 * Verhoeff checksum (the same algorithm UIDAI uses for the trailing check digit). This
 * proves the number is well-formed — not that it belongs to the person (that needs OTP
 * e-KYC). Good enough for a desk-side sanity check before billing.
 */
class ChecksumAadhaarVerifier implements AadhaarVerifier
{
    /** Verhoeff dihedral multiplication table (d). */
    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    /** Verhoeff permutation table (p). */
    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    public function supportsOtp(): bool
    {
        return false;
    }

    public function sendOtp(string $aadhaar): array
    {
        return ['ok' => false, 'ref_id' => null, 'message' => 'OTP verification is disabled.'];
    }

    public function verifyOtp(string $refId, string $otp): array
    {
        return ['valid' => false, 'name' => null, 'message' => 'OTP verification is disabled.'];
    }

    public function verify(string $aadhaar): array
    {
        $num = preg_replace('/\s+/', '', $aadhaar) ?? '';

        if (! preg_match('/^[2-9][0-9]{11}$/', $num)) {
            return ['valid' => false, 'message' => 'Aadhaar must be 12 digits (cannot start with 0 or 1).'];
        }

        if (! $this->verhoeffValid($num)) {
            return ['valid' => false, 'message' => 'Invalid Aadhaar number (checksum failed).'];
        }

        return ['valid' => true, 'message' => 'Aadhaar number is valid.'];
    }

    private function verhoeffValid(string $num): bool
    {
        $c = 0;
        $digits = array_reverse(str_split($num));
        foreach ($digits as $i => $d) {
            $c = self::D[$c][self::P[$i % 8][(int) $d]];
        }

        return $c === 0;
    }
}
