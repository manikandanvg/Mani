<?php

namespace App\Services\Geo;

use App\Models\Pincode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Pincode → State / District / Taluka (board phase-1, 2026-08-21).
 *
 * Local-first: the `pincodes` master (seeded via `pincodes:import`) answers
 * instantly and offline; a PIN not yet in the master is fetched once from the
 * free India Post API and cached as rows with source='api'.
 *
 * Indian 6-digit PINs only for now. Foreign postcodes (future distributors)
 * slot in here as extra drivers — GeoNames / Zippopotam.us both cover them.
 */
class PincodeService
{
    public const API = 'https://api.postalpincode.in/pincode/';

    /**
     * Returns ['state','district','talukas'=>[],'offices'=>[]] or null when the
     * PIN is unknown (or not an Indian 6-digit PIN).
     */
    public function lookup(?string $pin): ?array
    {
        $pin = trim((string) $pin);
        if (! preg_match('/^[1-9][0-9]{5}$/', $pin)) {
            return null;
        }

        $rows = Pincode::where('pincode', $pin)->get();
        if ($rows->isEmpty()) {
            $rows = $this->fetchFromApi($pin);
        } elseif ($rows->pluck('taluka')->filter()->isEmpty()
            && \Illuminate\Support\Facades\Cache::add("pincode-enrich:{$pin}", 1, now()->addWeek())) {
            // The bulk data.gov.in seed has no taluk column — enrich this PIN once
            // from the India Post API (the cache guard stops repeat attempts when
            // the API has no taluk either).
            if ($this->fetchFromApi($pin)->isEmpty()) {
                // API unreachable / empty answer — retry soon, not in a week
                \Illuminate\Support\Facades\Cache::put("pincode-enrich:{$pin}", 1, now()->addMinutes(10));
            }
            $rows = Pincode::where('pincode', $pin)->get();
        }
        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'state' => $rows->first()->state,
            'district' => $rows->first()->district,
            'talukas' => $rows->pluck('taluka')->filter()->unique()->values()->all(),
            'offices' => $rows->pluck('office')->filter()->unique()->values()->all(),
        ];
    }

    /** One-time live fetch; every returned office is cached into the master. */
    protected function fetchFromApi(string $pin): Collection
    {
        try {
            // The API's edge resets connections on Guzzle's default user-agent —
            // a browser-style UA goes through (verified 2026-08-21).
            $res = Http::withOptions(['verify' => app_ca()])->timeout(6)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) LordICL/1.0', 'Accept' => 'application/json'])
                ->get(self::API . $pin);
            $offices = $res->successful() ? (data_get($res->json(), '0.PostOffice') ?: []) : [];
        } catch (\Throwable) {
            $offices = [];   // offline / API down — the operator just types the fields
        }

        $rows = collect();
        foreach ($offices as $o) {
            $name = trim((string) ($o['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $row = Pincode::firstOrNew(['pincode' => $pin, 'office' => $name]);
            if (! $row->exists) {
                $row->fill([
                    'taluka' => $o['Block'] ?? ($o['Taluk'] ?? null),
                    'district' => $o['District'] ?? null,
                    'state' => $o['State'] ?? null,
                    'source' => 'api',
                ]);
            } elseif (blank($row->taluka)) {
                // seeded row (bulk CSV has no taluk) — backfill from the API
                $row->taluka = $o['Block'] ?? ($o['Taluk'] ?? null);
            }
            $row->save();
            $rows->push($row);
        }

        return $rows;
    }
}
