<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Ask the virtual L-BOX a question — text, or a WAV file to exercise the full
 * voice loop (Whisper STT → intent → TTS answer, played on the PC speakers).
 *
 *   php artisan lbox:ask LBX-SIM-0001 "what is today's gold rate"
 *   php artisan lbox:ask LBX-SIM-0001 --wav=question.wav
 */
class LboxAsk extends Command
{
    protected $signature = 'lbox:ask {serial} {text?}
        {--wav= : Path to a WAV question (uses the STT endpoint)}
        {--api= : Device API base URL (default: APP_URL/api/device/v1)}
        {--mute : Do not play the spoken answer}';

    protected $description = 'Ask the virtual L-BOX assistant a question (text or WAV)';

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

        if ($wav = $this->option('wav')) {
            if (! is_file($wav)) {
                $this->error("WAV not found: {$wav}");

                return self::FAILURE;
            }
            $this->comment('🎙  Uploading voice question (Whisper STT)...');
            $res = Http::acceptJson()->withToken($token)
                ->attach('audio', file_get_contents($wav), 'question.wav')
                ->post("{$api}/ai/voice");
        } else {
            $text = $this->argument('text');
            if (! $text) {
                $this->error('Give a question (text argument) or --wav=<file>.');

                return self::FAILURE;
            }
            $res = Http::acceptJson()->withToken($token)->post("{$api}/ai/ask", ['text' => $text]);
        }

        if (! $res->successful()) {
            $this->error('Ask failed: ' . ($res->json('message') ?? $res->status()));

            return self::FAILURE;
        }

        if ($res->json('transcript')) {
            $this->line('🎙  Heard: "' . $res->json('transcript') . '"');
        }
        $this->info("🤖 [{$res->json('intent')}] 🔊 \"{$res->json('answer')}\"");
        $this->playAudio($res->json('audio_url'));

        return self::SUCCESS;
    }

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
}
