<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Geocode\Geocoder;
use Illuminate\Console\Command;

/**
 * Fills branch latitude/longitude from the address when they're empty (store locator
 * map). Searches the full address, falling back to city + pincode. Rate-limited for
 * Nominatim's policy (~1 req/sec). Re-runnable; only touches branches missing coords.
 *
 *   php artisan branches:geocode               # all branches missing coords
 *   php artisan branches:geocode --limit=5     # try a handful first
 *   php artisan branches:geocode --all         # re-geocode everything
 */
class GeocodeBranches extends Command
{
    protected $signature = 'branches:geocode {--limit=0 : Max branches to process} {--all : Re-geocode even those with coords} {--sleep=1.1 : Seconds between requests}';

    protected $description = 'Geocode branch addresses into latitude/longitude for the store map';

    public function handle(Geocoder $geocoder): int
    {
        $query = Branch::query()->orderBy('id');
        if (! $this->option('all')) {
            $query->where(fn ($w) => $w->whereNull('latitude')->orWhere('latitude', 0));
        }
        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $branches = $query->get();
        if ($branches->isEmpty()) {
            $this->info('Nothing to geocode — all branches already have coordinates.');

            return self::SUCCESS;
        }

        $sleep = (float) $this->option('sleep');
        $ok = $fail = 0;
        $this->info("Geocoding {$branches->count()} branch(es)…");

        foreach ($branches as $b) {
            $full = $this->parts([$b->address, $b->city, $b->pincode, 'India']);
            $coords = $geocoder->geocode($full);

            // fall back to a coarser city/pincode lookup if the full address misses
            if (! $coords && ($b->city || $b->pincode)) {
                if ($sleep > 0) {
                    usleep((int) ($sleep * 1_000_000));
                }
                $coords = $geocoder->geocode($this->parts([$b->city, $b->pincode, 'India']));
            }

            if ($coords) {
                $b->update(['latitude' => $coords['lat'], 'longitude' => $coords['lng']]);
                $ok++;
                $this->line("  ✓ {$b->name} → {$coords['lat']}, {$coords['lng']}");
            } else {
                $fail++;
                $this->line("  <fg=yellow>✗ {$b->name} (no match)</>");
            }

            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }

        $this->info("Done — geocoded {$ok}, no match {$fail}.");

        return self::SUCCESS;
    }

    private function parts(array $bits): string
    {
        return implode(', ', array_filter(array_map('trim', array_map('strval', $bits))));
    }
}
