<?php

namespace App\Services\Geocode;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenStreetMap Nominatim — free, keyless geocoding. Usage policy requires a valid
 * User-Agent and max ~1 request/second (the command rate-limits). Biased to the
 * configured country for better matches on local addresses.
 */
class NominatimGeocoder implements Geocoder
{
    public function geocode(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        try {
            $endpoint = rtrim((string) (config('services.geocode.endpoint') ?: 'https://nominatim.openstreetmap.org'), '/');
            $res = Http::withOptions(['verify' => app_ca()])
                ->withHeaders(['User-Agent' => config('services.geocode.user_agent', 'LordICL Store Locator')])
                ->timeout(20)
                ->get($endpoint . '/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => config('services.geocode.country', 'in'),
                    'addressdetails' => 0,
                ])->throw()->json();

            if (! empty($res[0]['lat']) && ! empty($res[0]['lon'])) {
                return ['lat' => (float) $res[0]['lat'], 'lng' => (float) $res[0]['lon']];
            }
        } catch (\Throwable $e) {
            Log::warning('Geocode failed: ' . $e->getMessage());
        }

        return null;
    }
}
