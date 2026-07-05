<?php

namespace App\Services\Lbox;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Self-hosted speech-to-text (faster-whisper via scripts/lbox_stt.py). The box
 * records a short WAV after the wake word/button and uploads it; this returns
 * the transcript (Whisper handles Tamil + English well). Null on any failure.
 */
class SttService
{
    public function transcribe(string $absolutePath, ?string $langHint = null): ?string
    {
        if (! config('lbox.stt.enabled') || ! is_file($absolutePath)) {
            return null;
        }

        $cmd = sprintf(
            '%s %s --file %s --model %s --cache %s%s',
            config('lbox.stt.python', 'python'),
            escapeshellarg(base_path('scripts/lbox_stt.py')),
            escapeshellarg($absolutePath),
            escapeshellarg(config('lbox.stt.model', 'small')),
            escapeshellarg(storage_path('app/lbox-stt/hf-cache')),
            $langHint ? ' --lang ' . escapeshellarg($langHint) : '',
        );

        // The web server runs as a service account that can't see per-user
        // pip installs — point Python at the configured site-packages too.
        $env = array_filter(['PYTHONPATH' => config('lbox.stt.pythonpath')]);

        try {
            $result = Process::env($env)->timeout((int) config('lbox.stt.timeout', 300))->run($cmd);
        } catch (\Throwable $e) {
            Log::warning("[lbox-stt] failed: {$e->getMessage()}");

            return null;
        }

        if (! $result->successful()) {
            Log::warning('[lbox-stt] failed: ' . mb_substr($result->errorOutput(), 0, 400));

            return null;
        }

        $text = trim($result->output());

        return $text !== '' ? $text : null;
    }
}
