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
            && str_contains($process->command, '-v ta+f3')
            && str_contains($process->command, '-a 75 -p 60')
            && ! str_contains($process->command, $text)
            && $process->input === $text . "\n");
    }

    public function test_cached_empty_wav_is_rendered_again(): void
    {
        Storage::fake('public');
        config(['lbox.tts.enabled' => true, 'lbox.tts.engines.ta' => 'espeak', 'lbox.tts.espeak.bin' => 'espeak-ng']);
        $text = 'வணக்கம்';
        $cached = app(VoiceRenderService::class)->cachePath('espeak', 'ta', $text);
        Storage::disk('public')->put($cached, str_repeat('x', 44));   // a failed render from before

        Process::fake(function ($process) {
            preg_match("/-w '([^']+)'/", $process->command, $m) || preg_match('/-w "([^"]+)"/', $process->command, $m);
            @mkdir(dirname($m[1]), 0775, true);
            file_put_contents($m[1], str_repeat('x', 900));

            return Process::result('');
        });

        $this->assertSame($cached, app(VoiceRenderService::class)->render($text, 'ta'));
        Process::assertRanTimes(fn ($p) => str_contains($p->command, '--stdin'), 1);
        $this->assertSame(900, Storage::disk('public')->size($cached));
    }

    public function test_missing_piper_falls_back_to_espeak_for_english(): void
    {
        Storage::fake('public');
        config([
            'lbox.tts.enabled' => true,
            'lbox.tts.engines.en' => 'piper',
            'lbox.tts.piper.voices.en' => '/nowhere/en.onnx',   // not installed on live
            'lbox.tts.espeak.bin' => 'espeak-ng',
        ]);

        Process::fake(function ($process) {
            preg_match("/-w '([^']+)'/", $process->command, $m) || preg_match('/-w "([^"]+)"/', $process->command, $m);
            @mkdir(dirname($m[1]), 0775, true);
            file_put_contents($m[1], str_repeat('x', 700));

            return Process::result('');
        });

        $this->assertNotNull(app(VoiceRenderService::class)->render('Test announcement from Head Office', 'en'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'espeak-ng') && str_contains($p->command, '-v en-us'));
    }

    public function test_edge_neural_voice_renders_tamil_through_ffmpeg(): void
    {
        Storage::fake('public');
        config(['lbox.tts.enabled' => true, 'lbox.tts.engines.ta' => 'edge', 'lbox.tts.fallbacks' => ['espeak']]);
        $text = 'கிளை திறக்கப்பட்டது';

        Process::fake(function ($process) use ($text) {
            $cmd = $process->command;
            if (str_contains($cmd, 'edge-tts')) {
                // text must arrive via the -f file, not on the command line
                preg_match("/-f '([^']+)'/", $cmd, $m) || preg_match('/-f "([^"]+)"/', $cmd, $m);
                if (file_get_contents($m[1]) !== $text || str_contains($cmd, $text)) {
                    return Process::result('', 'bad text handoff', 1);
                }
                preg_match("/--write-media '([^']+)'/", $cmd, $w) || preg_match('/--write-media "([^"]+)"/', $cmd, $w);
                @mkdir(dirname($w[1]), 0775, true);
                file_put_contents($w[1], str_repeat('m', 2000));

                return Process::result('');
            }
            if (str_contains($cmd, 'ffmpeg')) {
                preg_match("/-sample_fmt s16 '([^']+)'/", $cmd, $o) || preg_match('/-sample_fmt s16 "([^"]+)"/', $cmd, $o);
                file_put_contents($o[1], str_repeat('w', 3000));

                return Process::result('');
            }

            return Process::result('', 'unexpected', 1);
        });

        $path = app(VoiceRenderService::class)->render($text, 'ta');

        $this->assertNotNull($path);
        $this->assertSame(3000, Storage::disk('public')->size($path));
        Process::assertRan(fn ($p) => str_contains($p->command, '--voice') && str_contains($p->command, 'ta-IN-PallaviNeural'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'ffmpeg') && str_contains($p->command, '-ar 22050 -ac 1'));
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'espeak'));
    }

    public function test_edge_unreachable_falls_back_to_espeak(): void
    {
        Storage::fake('public');
        config(['lbox.tts.enabled' => true, 'lbox.tts.engines.ta' => 'edge', 'lbox.tts.fallbacks' => ['espeak'],
            'lbox.tts.espeak.bin' => 'espeak-ng']);

        Process::fake(function ($process) {
            $cmd = $process->command;
            if (str_contains($cmd, 'edge-tts')) {
                return Process::result('', 'No internet', 1);
            }
            preg_match("/-w '([^']+)'/", $cmd, $m) || preg_match('/-w "([^"]+)"/', $cmd, $m);
            @mkdir(dirname($m[1]), 0775, true);
            file_put_contents($m[1], str_repeat('x', 800));

            return Process::result('');
        });

        $this->assertNotNull(app(VoiceRenderService::class)->render('வணக்கம்', 'ta'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'espeak-ng'));
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
