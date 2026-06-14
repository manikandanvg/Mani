<?php

namespace App\Services\Rates;

/** No-op provider: keep admin-entered rates (no auto-fetch). Default fallback. */
class ManualExchangeRateProvider implements ExchangeRateProvider
{
    public function rates(string $base): array
    {
        return [];
    }
}
