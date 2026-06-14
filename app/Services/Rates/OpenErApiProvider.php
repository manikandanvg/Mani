<?php

namespace App\Services\Rates;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * open.er-api.com — free, keyless FX rates. /v6/latest/{BASE} returns `rates` already
 * expressed as units per 1 base unit, which maps straight onto rate_to_base.
 */
class OpenErApiProvider implements ExchangeRateProvider
{
    public function rates(string $base): array
    {
        try {
            $res = Http::withOptions(['verify' => app_ca()])->timeout(10)
                ->get('https://open.er-api.com/v6/latest/' . strtoupper($base))
                ->throw()->json();

            if (($res['result'] ?? null) === 'success' && ! empty($res['rates']) && is_array($res['rates'])) {
                return array_map(fn ($v) => (float) $v, $res['rates']);
            }

            Log::warning('FX fetch: unexpected response', ['error' => $res['error-type'] ?? 'unknown']);
        } catch (\Throwable $e) {
            Log::warning('FX fetch failed: ' . $e->getMessage());
        }

        return [];
    }
}
