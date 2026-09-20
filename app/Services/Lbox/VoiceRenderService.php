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
            // A cached header-only file is a failed render from before the
            // stdin fix — drop it and render again instead of serving silence.
            if (Storage::disk(self::DISK)->size($path) > 44) {
                return $path;
            }
            Storage::disk(self::DISK)->delete($path);
        }

        $absolute = Storage::disk(self::DISK)->path($path);
        @mkdir(dirname($absolute), 0775, true);

        $ok = $this->run($engine, $text, $lang, $absolute);

        // The configured engine is missing or broke (live 2026-09-20: Piper is
        // not installed there, so every English line came back "TEXT ONLY").
        // eSpeak speaks every language we ship, so it is the safety net.
        if (! $ok && $engine !== 'espeak' && config('lbox.tts.espeak.bin')) {
            Log::warning("[lbox-tts] {$engine}/{$lang} failed - falling back to espeak");
            $ok = $this->run('espeak', $text, $lang, $absolute);
        }

        if ($ok && is_file($absolute) && filesize($absolute) <= 44) {
            // A bare WAV header = the engine spoke nothing. Seen on live for a
            // Tamil line when the text was lost between PHP and the shell.
            Log::warning("[lbox-tts] {$engine}/{$lang} produced an empty WAV for: {$text}");
            $ok = false;
        }

        return $ok && is_file($absolute) && filesize($absolute) > 44 ? $path : null;
    }

    protected function run(string $engine, string $text, string $lang, string $out): bool
    {
        try {
            $ok = match ($engine) {
                'piper' => $this->piper($text, $lang, $out),
                'espeak' => $this->espeak($text, $lang, $out),
                'command' => $this->command($text, $lang, $out),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning("[lbox-tts] {$engine}/{$lang} render failed: {$e->getMessage()}");
            $ok = false;
        }

        return $ok && is_file($out) && filesize($out) > 44;
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

        // Text goes in over STDIN (--stdin), never as a shell argument: with a
        // non-UTF-8 process locale escapeshellarg() silently DROPS every
        // multibyte character, so a Tamil line reached eSpeak as an empty
        // string and the box received no audio (live, 2026-09-20).
        $result = Process::timeout(60)->input($text . "\n")->run(
            escapeshellarg($bin) . " -v {$voice} -s {$speed} --stdin -w " . escapeshellarg($out),
        );
        if (! $result->successful()) {
            Log::warning("[lbox-tts] espeak/{$lang} exit {$result->exitCode()}: " . trim($result->errorOutput()));
        }

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
