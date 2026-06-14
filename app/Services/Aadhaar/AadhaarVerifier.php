<?php

namespace App\Services\Aadhaar;

/**
 * Provider-agnostic Aadhaar verification.
 *
 *  - Checksum driver: instant, offline Verhoeff validation (supportsOtp() === false).
 *  - OTP driver (Sandbox): real UIDAI e-KYC — sendOtp() to the linked mobile, then
 *    verifyOtp() (supportsOtp() === true).
 *
 * The admin toggles between them under System → Verification Settings.
 */
interface AadhaarVerifier
{
    /** True when this driver does OTP e-KYC (two-step) rather than instant validation. */
    public function supportsOtp(): bool;

    /** Instant validation result: [ 'valid' => bool, 'message' => string ]. */
    public function verify(string $aadhaar): array;

    /** Step 1 of OTP e-KYC — sends an OTP. Returns [ 'ok' => bool, 'ref_id' => ?string, 'message' => string ]. */
    public function sendOtp(string $aadhaar): array;

    /** Step 2 of OTP e-KYC — verifies the OTP. Returns [ 'valid' => bool, 'name' => ?string, 'message' => string ]. */
    public function verifyOtp(string $refId, string $otp): array;
}
