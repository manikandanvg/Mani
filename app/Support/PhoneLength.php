<?php

namespace App\Support;

/**
 * National phone-number digit lengths per dialling code (board fix 2026-08-09:
 * "India needs 10 digits"). Lengths are the national significant number — digits
 * AFTER the country code, no leading 0. Codes missing from the map fall back to
 * the ITU-T E.164 envelope of 7–15 digits.
 */
class PhoneLength
{
    /** dial code => [min digits, max digits] */
    public const LENGTHS = [
        '+91' => [10, 10],   // India
        '+971' => [9, 9],    // UAE
        '+966' => [9, 9],    // Saudi Arabia
        '+965' => [8, 8],    // Kuwait
        '+974' => [8, 8],    // Qatar
        '+968' => [8, 8],    // Oman
        '+973' => [8, 8],    // Bahrain
        '+65' => [8, 8],     // Singapore
        '+60' => [9, 10],    // Malaysia
        '+44' => [10, 10],   // United Kingdom
        '+1' => [10, 10],    // USA / Canada
        '+61' => [9, 9],     // Australia
        '+94' => [9, 9],     // Sri Lanka
        '+880' => [10, 10],  // Bangladesh
        '+977' => [10, 10],  // Nepal
        '+93' => [9, 9],     // Afghanistan
        '+975' => [8, 8],    // Bhutan
        '+960' => [7, 7],    // Maldives
        '+49' => [10, 11],   // Germany
        '+33' => [9, 9],     // France
        '+39' => [9, 10],    // Italy
        '+34' => [9, 9],     // Spain
        '+27' => [9, 9],     // South Africa
        '+254' => [9, 9],    // Kenya
        '+234' => [10, 10],  // Nigeria
        '+852' => [8, 8],    // Hong Kong
        '+86' => [11, 11],   // China
        '+81' => [10, 10],   // Japan
        '+64' => [8, 10],    // New Zealand
        '+92' => [10, 10],   // Pakistan
    ];

    /** [min, max] for a dial code (E.164 7–15 fallback). */
    public static function lengths(?string $dial): array
    {
        return self::LENGTHS[trim((string) $dial)] ?? [7, 15];
    }

    /** Digits only — operators paste numbers with spaces/dashes/brackets. */
    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /** Error message when the number doesn't fit its country, null when it does. */
    public static function check(?string $dial, string $value): ?string
    {
        $digits = self::digits($value);
        [$min, $max] = self::lengths($dial);

        if (strlen($digits) < $min || strlen($digits) > $max) {
            return __('Phone for :dial must be :len digits (you entered :n).', [
                'dial' => $dial ?: '+91',
                'len' => $min === $max ? $min : "{$min}–{$max}",
                'n' => strlen($digits),
            ]);
        }

        return null;
    }

    /** Field helper text, e.g. "10 digits (India +91)". */
    public static function hint(?string $dial): string
    {
        [$min, $max] = self::lengths($dial);
        $len = $min === $max ? $min : "{$min}–{$max}";

        return __(':len digits after :dial', ['len' => $len, 'dial' => $dial ?: '+91']);
    }
}
