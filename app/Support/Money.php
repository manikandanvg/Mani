<?php

namespace App\Support;

use App\Models\Currency;

/**
 * Money display for a global, multi-currency back-office. All amounts are STORED in
 * the base currency (see currencies.is_base); this converts/formats for display.
 *
 *   rate_to_base: 1 base unit = rate_to_base units of that currency.
 */
class Money
{
    /** The base currency row (cached per request). */
    public static function base(): ?Currency
    {
        return once(fn () => Currency::where('is_base', true)->first()
            ?? Currency::where('is_active', true)->first());
    }

    public static function currency(?string $code): ?Currency
    {
        if (blank($code)) {
            return self::base();
        }

        $map = once(fn () => Currency::all()->keyBy(fn ($c) => strtoupper($c->code)));

        return $map[strtoupper($code)] ?? self::base();
    }

    /** Convert a base-currency amount into $code (default: leave in base). */
    public static function convert(float $baseAmount, ?string $code = null): float
    {
        $currency = self::currency($code);
        $rate = $currency ? (float) $currency->rate_to_base : 1.0;

        return round($baseAmount * ($rate ?: 1.0), $currency->decimals ?? 2);
    }

    /** The visitor's chosen display currency (storefront), defaulting to base. */
    public static function current(): string
    {
        return session('currency') ?: (self::base()?->code ?? 'INR');
    }

    /** Format a base amount in the visitor's chosen currency (storefront helper). */
    public static function display(float|int|string|null $baseAmount): string
    {
        return self::format($baseAmount, self::current());
    }

    /** Format a base-currency amount for display in $code (default: base). */
    public static function format(float|int|string|null $baseAmount, ?string $code = null): string
    {
        $baseAmount = (float) ($baseAmount ?? 0);
        $currency = self::currency($code);
        $decimals = $currency->decimals ?? 2;
        $symbol = $currency->symbol ?? '';
        $value = self::convert($baseAmount, $code);

        $code = strtoupper($currency->code ?? 'INR');

        return trim($symbol . ' ' . self::group($value, $decimals, $code));
    }

    /**
     * Digit grouping per currency region (board 2026-08-12 item 3): INR uses the
     * Indian lakh/crore system — ₹18,83,97,300.00, never ₹188,397,300.00. Other
     * currencies keep Western thousands.
     */
    public static function group(float|int|string|null $value, int $decimals = 2, string $code = 'INR'): string
    {
        $value = (float) ($value ?? 0);

        if ($code !== 'INR') {
            return number_format($value, $decimals);
        }

        $sign = $value < 0 ? '-' : '';
        $fixed = number_format(abs($value), $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $fixed, 2), 2, '');

        // Last 3 digits stand alone; every 2 digits above them get a comma.
        if (strlen($int) > 3) {
            $head = substr($int, 0, -3);
            $int = preg_replace('/\B(?=(\d{2})+$)/', ',', $head) . ',' . substr($int, -3);
        }

        return $sign . $int . ($decimals > 0 ? '.' . $frac : '');
    }

    /** Indian-grouped rupee string WITH the ₹ sign — for services/blades that hand-format. */
    public static function inr(float|int|string|null $value, int $decimals = 2): string
    {
        return '₹' . self::group($value, $decimals, 'INR');
    }
}
