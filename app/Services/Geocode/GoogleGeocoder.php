<?php

namespace App\Services\Geocode;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Google Geocoding API (key-based). Higher accuracy; set services.geocode.key. */
class GoogleGeocoder implements Geocoder
{
    public function geocode(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        try {
            $res = Http::withOptions(['verify' => app_ca()])->timeout(20)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $query,
                    'key' => config('services.geocode.key'),
                    'region' => config('services.geocode.country', 'in'),
                ])->throw()->json();

            $loc = $res['results'][0]['geometry']['location'] ?? null;
            if ($loc) {
                return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
            }
        } catch (\Throwable $e) {
            Log::warning('Google geocode failed: ' . $e->getMessage());
        }

        return null;
    }
}
