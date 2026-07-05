<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Virtual L-BOX — exercises the real device API end-to-end with NO hardware:
 * redeems a pairing code, heartbeats, polls the voice queue and "speaks" (prints),
 * ACKs, and reports OTA availability. Token persists per serial under
 * storage/app/lbox-sim/, so later runs skip pairing exactly like a real box.
 *
 *   php artisan lbox:simulate LBX-LITE-0001 --code=AB12CD34   (first run)
 *   php artisan lbox:simulate LBX-LITE-0001                   (subsequent runs)
 *   php artisan lbox:tap LBX-LITE-0001 TAG001122              (separate console: RFID tap)
 */
class LboxSimulate extends Command
{
    protected $signature = 'lbox:simulate {serial}
        {--code= : One-time pairing code (needed on first run)}
        {--api= : Device API base URL (default: APP_URL/api/device/v1)}
        {--heartbeat=30 : Seconds between heartbeats}
        {--cycles=0 : Stop after N poll cycles (0 = run until Ctrl+C)}
        {--mute : Do not play rendered voice WAVs on the PC speakers}';

    protected $description = 'Run a virtual L-BOX against the device API (no hardware needed)';

    public function handle(): int
    {
        $serial = $this->argument('serial');
        $api = rtrim($this->option('api') ?: config('app.url') . '/api/device/v1', '/');
        $tokenFile = "lbox-sim/{$serial}.json";

        $token = $this->token($tokenFile);

        if (! $token) {
            $code = $this->option('code');
            if (! $code) {
                $this->error("No stored token for {$serial}. First run needs --code=<pairing code> (Devices page → Pairing code).");

                return self::FAILURE;
            }

            $res = Http::acceptJson()->post("{$api}/register", [
                'serial_no' => $serial,
                'pairing_code' => $code,
                'board_type' => 'lite',
                'firmware_version' => 'SIM-1.0.0',
            ]);

            if (! $res->successful()) {
                $this->error('Pairing failed: ' . ($res->json('message') ?? $res->status()));

                return self::FAILURE;
            }

            $token = $res->json('token');
            Storage::put($tokenFile, json_encode(['token' => $token], JSON_PRETTY_PRINT));
            $this->info("✅ Paired as \"{$res->json('device.name')}\" @ {$res->json('device.branch')} — token stored.");
        }

        $this->info("📟 L-BOX simulator [{$serial}] online → {$api}");
        $this->comment('   Polls the voice queue every 3s. Ctrl+C to power off.');

        $client = fn () => Http::acceptJson()->withToken($token);
        $heartbeatEvery = max(5, (int) $this->option('heartbeat'));
        $maxCycles = (int) $this->option('cycles');
        $lastBeat = 0;
        $cycle = 0;

        while (true) {
            $cycle++;

            if (time() - $lastBeat >= $heartbeatEvery) {
                $beat = $client()->post("{$api}/heartbeat", [
                    'firmware_version' => 'SIM-1.0.0',
                    'battery_pct' => 80 + ($cycle % 15),
                    'rssi' => -55 - ($cycle % 10),
                    'uptime_s' => $cycle * 3,
                ]);
                if ($beat->successful()) {
                    $lastBeat = time();
                    $ota = $beat->json('ota_available') ? ' · OTA AVAILABLE ⬆️' : '';
                    $this->line(now()->format('H:i:s') . " 💓 heartbeat ok · pending voice: {$beat->json('pending_announcements')}{$ota}");
                } else {
                    $this->warn(now()->format('H:i:s') . ' 💔 heartbeat failed: ' . ($beat->json('message') ?? $beat->status()));
                }
            }

            $poll = $client()->get("{$api}/announcements");
            foreach ($poll->json('data') ?? [] as $line) {
                $this->info(now()->format('H:i:s') . " 🔊 SPEAKING [{$line['type']}]: \"{$line['message']}\"");
                $this->playAudio($line['audio_url'] ?? null);
                $client()->post("{$api}/announcements/ack", ['ids' => [$line['id']]]);
            }

            if ($maxCycles > 0 && $cycle >= $maxCycles) {
                $this->comment('Cycle limit reached — powering off.');

                return self::SUCCESS;
            }

            sleep(3);
        }
    }

    /** Play the rendered WAV on the PC speakers (Windows only; silent no-op elsewhere). */
    protected function playAudio(?string $url): void
    {
        if (! $url || PHP_OS_FAMILY !== 'Windows' || $this->option('mute')) {
            return;
        }

        $wav = Http::get($url);
        if (! $wav->successful()) {
            return;
        }

        $tmp = storage_path('app/lbox-sim-speak.wav');
        file_put_contents($tmp, $wav->body());
        shell_exec('powershell -NoProfile -Command "(New-Object Media.SoundPlayer \'' . $tmp . '\').PlaySync()"');
    }

    protected function token(string $file): ?string
    {
        if (! Storage::exists($file)) {
            return null;
        }

        return json_decode(Storage::get($file), true)['token'] ?? null;
    }
}
