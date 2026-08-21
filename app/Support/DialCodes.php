<?php

namespace App\Support;

/**
 * Phone dialling codes shared by the Sales billing form and the storefront
 * checkout. India (+91) is the default; the spread covers the walk-in range.
 * The stored value is the dial code itself.
 */
class DialCodes
{
    public static function options(): array
    {
        return [
            '+91' => 'India (+91)',
            '+971' => 'UAE (+971)',
            '+966' => 'Saudi Arabia (+966)',
            '+965' => 'Kuwait (+965)',
            '+974' => 'Qatar (+974)',
            '+968' => 'Oman (+968)',
            '+973' => 'Bahrain (+973)',
            '+65' => 'Singapore (+65)',
            '+60' => 'Malaysia (+60)',
            '+44' => 'United Kingdom (+44)',
            '+1' => 'USA / Canada (+1)',
            '+61' => 'Australia (+61)',
            '+94' => 'Sri Lanka (+94)',
            '+880' => 'Bangladesh (+880)',
            '+977' => 'Nepal (+977)',
            '+93' => 'Afghanistan (+93)',
            '+975' => 'Bhutan (+975)',
            '+960' => 'Maldives (+960)',
            '+49' => 'Germany (+49)',
            '+33' => 'France (+33)',
            '+39' => 'Italy (+39)',
            '+34' => 'Spain (+34)',
            '+27' => 'South Africa (+27)',
            '+254' => 'Kenya (+254)',
            '+234' => 'Nigeria (+234)',
            '+852' => 'Hong Kong (+852)',
            '+86' => 'China (+86)',
            '+81' => 'Japan (+81)',
            '+64' => 'New Zealand (+64)',
            '+92' => 'Pakistan (+92)',
        ];
    }
}
