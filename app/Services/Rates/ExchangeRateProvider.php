<?php

namespace App\Services\Rates;

/**
 * Foreign-exchange rate provider. Drivers fetch how many units of each currency equal
 * ONE unit of the base currency — i.e. the value stored in currencies.rate_to_base.
 * Provider-agnostic (cf. Translator / PanVerifier); swap via config('services.exchange').
 */
interface ExchangeRateProvider
{
    /**
     * @param  string  $base  ISO 4217 base currency code (e.g. INR)
     * @return array<string,float>  map of CURRENCY CODE => units per 1 base. Empty on failure.
     */
    public function rates(string $base): array;
}
