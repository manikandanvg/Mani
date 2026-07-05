<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Simulate an RFID card tap on a virtual L-BOX (run lbox:simulate first so the
 * serial has a stored token). Prints what the box would speak/display.
 *
 *   php artisan lbox:tap LBX-LITE-0001 TAG001122
 */
class LboxTap extends Command
{
    protected $signature = 'lbox:tap {serial} {tag}
        {--api= : Device API base URL (default: APP_URL/api/device/v1)}';

    protected $description = 'Simulate an RFID attendance tap on a virtual L-BOX';

    public function handle(): int
    {
        $serial = $this->argument('serial');
        $api = rtrim($this->option('api') ?: config('app.url') . '/api/device/v1', '/');
        $tokenFile = "lbox-sim/{$serial}.json";

        if (! Storage::exists($tokenFile)) {
            $this->error("No stored token for {$serial} — run lbox:simulate with --code first.");

            return self::FAILURE;
        }

        $token = json_decode(Storage::get($tokenFile), true)['token'] ?? null;

        $res = Http::acceptJson()->withToken($token)
            ->post("{$api}/attendance", ['tag_uid' => $this->argument('tag')]);

        if (! $res->successful()) {
            $this->error('Tap failed: ' . ($res->json('message') ?? $res->status()));

            return self::FAILURE;
        }

        $icon = match ($res->json('result')) {
            'checked_in' => '🟢', 'checked_out' => '🟠', 'duplicate' => '🔁',
            'day_complete' => '✅', default => '⛔',
        };
        $this->info("{$icon} [{$res->json('result')}] 🔊 \"{$res->json('message')}\"");

        return self::SUCCESS;
    }
}
