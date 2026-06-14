<?php

namespace App\Services\Rates;

use App\Models\Currency;
use App\Support\Money;

/**
 * Pulls live FX from the configured provider and writes each active currency's
 * rate_to_base. The base currency is pinned to 1. Metal rates are NOT touched here —
 * they stay admin-entered (the base metal rate everything else derives from via FX).
 */
class ExchangeRateUpdater
{
    public function __construct(protected ExchangeRateProvider $provider) {}

    /**
     * @return array{ok:bool,updated:int,missing:array<int,string>,base:?string,message:string}
     */
    public function update(): array
    {
        $base = Money::base();
        if (! $base) {
            return ['ok' => false, 'updated' => 0, 'missing' => [], 'base' => null, 'message' => 'No base currency set.'];
        }

        $rates = $this->provider->rates($base->code);
        if (empty($rates)) {
            return ['ok' => false, 'updated' => 0, 'missing' => [], 'base' => $base->code,
                'message' => 'Provider returned no rates (check the driver / network).'];
        }

        // Normalise keys to uppercase for case-insensitive lookup.
        $rates = array_change_key_case($rates, CASE_UPPER);

        $updated = 0;
        $missing = [];

        foreach (Currency::where('is_active', true)->get() as $currency) {
            if ($currency->is_base) {
                if ((float) $currency->rate_to_base !== 1.0) {
                    $currency->update(['rate_to_base' => 1]);
                }
                continue;
            }

            $code = strtoupper($currency->code);
            if (! array_key_exists($code, $rates)) {
                $missing[] = $code;
                continue;
            }

            $currency->update(['rate_to_base' => $rates[$code]]);
            $updated++;
        }

        return [
            'ok' => true,
            'updated' => $updated,
            'missing' => $missing,
            'base' => $base->code,
            'message' => "Updated {$updated} currencies from {$base->code}"
                . ($missing ? ' · not offered by provider: ' . implode(', ', $missing) : '') . '.',
        ];
    }
}
