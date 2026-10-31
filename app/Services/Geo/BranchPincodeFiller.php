<?php

namespace App\Services\Geo;

use App\Models\Branch;
use App\Models\Pincode;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Board 2026-10-31 (common 1): the pincode is the key of the network hierarchy (auto
 * mapping of distributors to branches by PIN), so every branch must carry one.
 *
 * For a branch without a pincode:
 *   1. a 6-digit Indian PIN written inside the address ("…, PERAMBALUR 621212.") is used;
 *   2. otherwise the city, then each comma-separated part of the address, is matched
 *      against the pincode master (post-office / taluka / district names) — a match is only
 *      taken when it resolves to ONE pincode, or when every candidate office lies in the same
 *      taluka (then the head office / lowest PIN of that place is used);
 *   3. otherwise the branch is reported as still missing.
 */
class BranchPincodeFiller
{
    public function missing(): Collection
    {
        return Branch::query()
            ->where(fn ($q) => $q->whereNull('pincode')->orWhere('pincode', ''))
            ->orderBy('name')
            ->get();
    }

    /** @return array{filled:int, missing:int, rows: array<int, array{branch:Branch, pincode:?string, how:string}>} */
    public function fillAll(bool $dryRun = false): array
    {
        $rows = [];
        $filled = 0;
        foreach ($this->missing() as $branch) {
            [$pin, $how] = $this->resolve($branch);
            if ($pin && ! $dryRun) {
                $branch->update(['pincode' => $pin]);
            }
            if ($pin) {
                $filled++;
            }
            $rows[] = ['branch' => $branch, 'pincode' => $pin, 'how' => $how];
        }

        return ['filled' => $filled, 'missing' => count($rows) - $filled, 'rows' => $rows];
    }

    /** @return array{0:?string, 1:string} [pincode|null, how] */
    public function resolve(Branch $branch): array
    {
        $address = (string) $branch->address;
        if (preg_match('/\b([1-9]\d{5})\b/', $address, $m)) {
            if (Pincode::where('pincode', $m[1])->exists() || $branch->country === 'IN') {
                return [$m[1], 'from address'];
            }
        }
        if (($branch->country ?: 'IN') !== 'IN') {
            return [null, 'foreign branch — enter manually'];
        }

        $candidates = array_filter(array_map('trim', array_merge(
            [(string) $branch->city],
            preg_split('/[,\n]+/', $address) ?: [],
        )));
        foreach ($candidates as $place) {
            $place = preg_replace('/\b(north|south|east|west|taluk|taluka|district|dist|road|street|st|main)\b\.?/i', '', $place);
            $place = trim(preg_replace('/\s+/', ' ', (string) $place));
            if (mb_strlen($place) < 4 || preg_match('/\d/', $place)) {
                continue;
            }
            $pin = $this->pinForPlace($place);
            if ($pin) {
                return [$pin, 'matched "' . $place . '"'];
            }
        }
        // Master empty / no match → India Post "post office by name" API (same source the
        // Sales screen uses for PIN look-ups); the town's head office wins.
        foreach ($candidates as $place) {
            $place = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(north|south|east|west|taluk|taluka|district|dist)\b\.?/i', '', $place)));
            if (mb_strlen($place) < 4 || preg_match('/\d/', $place)) {
                continue;
            }
            if ($pin = $this->pinForPlaceApi($place)) {
                return [$pin, 'India Post API "' . $place . '"'];
            }
        }

        return [null, 'no match — enter manually'];
    }

    /** https://api.postalpincode.in/postoffice/{name} → head office PIN of that town, else the lowest PIN. */
    protected function pinForPlaceApi(string $place): ?string
    {
        try {
            $res = \Illuminate\Support\Facades\Http::withOptions(['verify' => app_ca()])->timeout(6)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) LordICL/1.0', 'Accept' => 'application/json'])
                ->get('https://api.postalpincode.in/postoffice/' . rawurlencode($place));
            $offices = $res->successful() ? (data_get($res->json(), '0.PostOffice') ?: []) : [];
        } catch (\Throwable) {
            return null;
        }
        if (! $offices) {
            return null;
        }
        $rows = collect($offices)->map(fn ($o) => [
            'pin' => (string) ($o['Pincode'] ?? ''), 'name' => (string) ($o['Name'] ?? ''), 'type' => (string) ($o['BranchType'] ?? ''),
        ])->filter(fn ($o) => preg_match('/^[1-9]\d{5}$/', $o['pin']));
        if ($rows->isEmpty()) {
            return null;
        }
        $head = $rows->first(fn ($o) => stripos($o['type'], 'Head') !== false)
            ?? $rows->first(fn ($o) => Str::lower($o['name']) === Str::lower($place))
            ?? $rows->first(fn ($o) => stripos($o['type'], 'Sub') !== false);

        return $head['pin'] ?? $rows->sortBy('pin')->first()['pin'];
    }

    protected function pinForPlace(string $place): ?string
    {
        $like = '%' . $place . '%';
        $rows = Pincode::query()
            ->where(fn ($q) => $q->where('office', 'like', $like)->orWhere('taluka', $place)->orWhere('district', $place))
            ->get(['pincode', 'office', 'taluka']);
        if ($rows->isEmpty()) {
            return null;
        }
        $pins = $rows->pluck('pincode')->unique();
        if ($pins->count() === 1) {
            return $pins->first();
        }
        // Prefer the head / sub office named exactly after the place.
        $exact = $rows->first(fn ($r) => Str::lower(trim(preg_replace('/\s*(H\.?O\.?|S\.?O\.?|B\.?O\.?)\s*$/i', '', $r->office))) === Str::lower($place));
        if ($exact) {
            return $exact->pincode;
        }
        // Same taluka everywhere → one town, several offices: take the lowest PIN.
        if ($rows->pluck('taluka')->filter()->unique()->count() === 1) {
            return $pins->sort()->first();
        }

        return null;
    }
}
