<?php

namespace Tests\Feature;

use App\Services\Lbox\VoiceRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * eSpeak must receive the text over STDIN, never as a shell argument: with a
 * non-UTF-8 locale escapeshellarg() strips multibyte characters, which is how
 * a Tamil line rendered as an empty WAV on live (2026-09-20).
 */
class VoiceRenderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_espeak_gets_tamil_text_over_stdin_and_not_on_the_command_line(): void
    {
        Storage::fake('public');
        config(['lbox.tts.enabled' => true, 'lbox.tts.engines.ta' => 'espeak', 'lbox.tts.espeak.bin' => 'espeak-ng']);
        $text = 'கிளை திறக்கப்பட்டது. நல்ல நாள்!';

        Process::fake(function ($process) {
            // pretend the engine wrote a real WAV
            preg_match("/-w '([^']+)'/", $process->command, $m) || preg_match('/-w "([^"]+)"/', $process->command, $m);
            @mkdir(dirname($m[1]), 0775, true);
            file_put_contents($m[1], str_repeat('x', 500));

            return Process::result('');
        });

        $path = app(VoiceRenderService::class)->render($text, 'ta');

        $this->assertNotNull($path);
        Process::assertRan(fn ($process) => str_contains($process->command, '--stdin')
            && str_contains($process->command, '-v ta')
            && ! str_contains($process->command, $text)
            && $process->input === $text . "\n");
    }

    public function test_empty_wav_counts_as_no_audio(): void
    {
        Storage::fake('public');
        config(['lbox.tts.enabled' => true, 'lbox.tts.engines.ta' => 'espeak', 'lbox.tts.espeak.bin' => 'espeak-ng']);

        Process::fake(function ($process) {
            preg_match("/-w '([^']+)'/", $process->command, $m) || preg_match('/-w "([^"]+)"/', $process->command, $m);
            @mkdir(dirname($m[1]), 0775, true);
            file_put_contents($m[1], str_repeat('x', 44));   // header only

            return Process::result('');
        });

        $this->assertNull(app(VoiceRenderService::class)->render('வணக்கம்', 'ta'));
    }
}
