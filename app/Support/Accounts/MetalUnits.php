<?php

namespace App\Support\Accounts;

use Illuminate\Support\Carbon;

/**
 * Small unit helpers shared by the Balance Sheet Report sources. Both books keep
 * metal stock in PIECES of a weight-denominated product ("300 mg Gold", "8 g Gold",
 * "1000 g Silver"), so grams are always pieces × grams-per-piece; the new catalog's
 * default_weight is used when set, otherwise the weight is read from the product name.
 */
final class MetalUnits
{
    public const METALS = ['gold', 'silver'];

    /** Grams per piece from a product name such as "300 mg Gold" or "8 g Gold"; null when the name carries no weight. */
    public static function gramsFromName(?string $name): ?float
    {
        if (! $name || ! preg_match('/(\d+(?:\.\d+)?)\s*(mg|kg|gms?|grams?|g)\b/i', $name, $m)) {
            return null;
        }
        $v = (float) $m[1];

        return match (strtolower($m[2])) {
            'mg' => $v / 1000,
            'kg' => $v * 1000,
            default => $v,
        };
    }

    /** 'bullion' for weight-denominated coins / bars / biscuits (the Lord products), 'jewellery' for everything else. */
    public static function kind(?string $name): string
    {
        return $name && preg_match('/coin|bar\b|bars\b|bullion|biscuit|^\s*\d+(?:\.\d+)?\s*(mg|kg|gms?|grams?|g)\b/i', $name)
            ? 'bullion'
            : 'jewellery';
    }

    public static function metal(?string $raw): string
    {
        $m = strtolower(trim((string) $raw));

        return $m === '' ? 'other' : $m;
    }

    /** Indian financial year label (April–March) for a date: 2026-10-15 → "2026-27". */
    public static function fy(Carbon $d): string
    {
        $start = $d->month >= 4 ? $d->year : $d->year - 1;

        return $start.'-'.str_pad((string) (($start + 1) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function fyStart(Carbon $d): Carbon
    {
        return Carbon::create($d->month >= 4 ? $d->year : $d->year - 1, 4, 1)->startOfDay();
    }
}
