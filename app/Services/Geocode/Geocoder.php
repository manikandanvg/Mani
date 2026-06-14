<?php

namespace App\Services\Geocode;

/**
 * Address → coordinates. Provider-agnostic (cf. Translator / PanVerifier); swap via
 * config('services.geocode.driver'). Returns ['lat' => float, 'lng' => float] or null.
 */
interface Geocoder
{
    public function geocode(string $query): ?array;
}
