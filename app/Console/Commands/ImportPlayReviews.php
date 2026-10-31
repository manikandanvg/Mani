<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use App\Services\Google\PlayReviews;
use Illuminate\Console\Command;

/**
 * Board 2026-10-31 (item 14): Play Store reviews → Website → Testimonials.
 *
 *   php artisan testimonials:import-play-reviews               # 4★ and 5★ reviews with text, unpublished
 *   php artisan testimonials:import-play-reviews --publish     # …and publish them straight away
 *   php artisan testimonials:import-play-reviews --min-rating=3
 *
 * Scheduled daily (routes/console.php) once GOOGLE_PLAY_PACKAGE and
 * GOOGLE_PLAY_SERVICE_ACCOUNT_JSON are set; Google only serves the last 7 days of reviews.
 */
class ImportPlayReviews extends Command
{
    protected $signature = 'testimonials:import-play-reviews
        {--min-rating=4 : Lowest star rating to import}
        {--publish : Publish imported reviews immediately (default: unpublished, for HQ review)}';

    protected $description = 'Import Google Play reviews of the app as testimonials';

    public function handle(PlayReviews $play): int
    {
        if (! $play->configured()) {
            $this->warn('Google Play is not configured: set GOOGLE_PLAY_PACKAGE and GOOGLE_PLAY_SERVICE_ACCOUNT_JSON (path to the service-account key) in .env.');

            return self::SUCCESS;   // scheduled run must not fail while unconfigured
        }

        try {
            $reviews = $play->fetch();
        } catch (\Throwable $e) {
            $this->error('Play API error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $min = (int) $this->option('min-rating');
        [$new, $updated, $skipped] = [0, 0, 0];
        foreach ($reviews as $r) {
            if ($r['rating'] < $min || $r['text'] === '' || $r['id'] === '') {
                $skipped++;
                continue;
            }
            $t = Testimonial::firstOrNew(['source' => 'google_play', 'source_ref' => $r['id']]);
            $isNew = ! $t->exists;
            $t->fill([
                'name' => mb_substr($r['author'], 0, 150),
                'location' => 'Google Play' . ($r['device'] ? ' · ' . mb_substr($r['device'], 0, 120) : ''),
                'rating' => max(1, min(5, $r['rating'])),
                'body' => ['en' => $r['text']] + (array) ($t->body ?? []),
                'is_published' => $isNew ? (bool) $this->option('publish') : (bool) $t->is_published,
                'sort' => $t->sort ?: 100,
            ]);
            if (! $t->created_at) {
                $t->created_at = $r['at'];
            }
            $t->save();
            $isNew ? $new++ : $updated++;
        }

        $this->info("Play reviews fetched: " . count($reviews) . " · new: {$new} · updated: {$updated} · skipped (below {$min}★ / empty): {$skipped}");

        return self::SUCCESS;
    }
}
