<?php

namespace App\Services\Lbox;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Free, self-hosted text→speech for L-BOX voice lines. Renders through the
 * per-language engine from config/lbox.php (Piper for English, eSpeak-NG for
 * Tamil) into a content-addressed WAV on the public disk — identical lines
 * render once, ever. Any failure returns null and the box falls back to beeps,
 * so a missing engine can never break the announcement flow.
 */
class VoiceRenderService
{
    public const DISK = 'public';

    /** Render (or reuse) the WAV for a line. Returns the storage path, or null. */
    public function render(string $text, string $lang = 'en'): ?string
    {
        if (! config('lbox.tts.enabled')) {
            return null;
        }

        $engine = config("lbox.tts.engines.{$lang}");
        if (! $engine) {
            return null;
        }

        $path = 'lbox-voice/' . sha1("{$engine}|{$lang}|{$text}") . '.wav';
        if (Storage::disk(self::DISK)->exists($path)) {
            return $path;
        }

        $absolute = Storage::disk(self::DISK)->path($path);
        @mkdir(dirname($absolute), 0775, true);

        try {
            $ok = match ($engine) {
                'piper' => $this->piper($text, $lang, $absolute),
                'espeak' => $this->espeak($text, $lang, $absolute),
                'command' => $this->command($text, $lang, $absolute),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning("[lbox-tts] {$engine}/{$lang} render failed: {$e->getMessage()}");
            $ok = false;
        }

        return $ok && is_file($absolute) && filesize($absolute) > 44 ? $path : null;
    }

    protected function piper(string $text, string $lang, string $out): bool
    {
        $model = config("lbox.tts.piper.voices.{$lang}");
        if (! $model || ! is_file($model)) {
            return false;
        }

        $cmd = config('lbox.tts.piper.command') . ' -m ' . escapeshellarg($model) . ' -f ' . escapeshellarg($out);
        // Web server runs as a service account — point Python at the per-user pip packages.
        $env = array_filter(['PYTHONPATH' => config('lbox.stt.pythonpath')]);
        $result = Process::env($env)->input($text)->timeout(60)->run($cmd);

        return $result->successful();
    }

    protected function espeak(string $text, string $lang, string $out): bool
    {
        $bin = config('lbox.tts.espeak.bin');
        $voice = config("lbox.tts.espeak.voices.{$lang}", $lang);
        $speed = (int) config('lbox.tts.espeak.speed_wpm', 150);

        $result = Process::timeout(60)->run(
            escapeshellarg($bin) . " -v {$voice} -s {$speed} -w " . escapeshellarg($out) . ' ' . escapeshellarg($text),
        );

        return $result->successful();
    }

    protected function command(string $text, string $lang, string $out): bool
    {
        $template = config('lbox.tts.command.template');
        if (! $template) {
            return false;
        }

        $cmd = str_replace(
            ['{text}', '{lang}', '{out}'],
            [escapeshellarg($text), escapeshellarg($lang), escapeshellarg($out)],
            $template,
        );

        return Process::timeout(120)->run($cmd)->successful();
    }
}
