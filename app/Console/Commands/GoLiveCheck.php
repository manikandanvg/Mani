<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Go-live doctor (2026-08-23). One read-only pass over everything that has
 * bitten us on the hosted server: URL/debug, storage link, PHP functions the
 * voice/PDF tools need, pending migrations, and whether each payment/
 * messaging integration actually has its keys. Prints PASS/WARN/FAIL per row;
 * exit code 1 when anything FAILs. Run after every deploy: php artisan golive:check
 */
class GoLiveCheck extends Command
{
    protected $signature = 'golive:check';

    protected $description = 'Check server config, storage, PHP and integration keys for production readiness';

    protected bool $failed = false;

    public function handle(): int
    {
        $this->section('App');
        $url = (string) config('app.url');
        $this->row('APP_URL', $url, str_starts_with($url, 'https://') ? 'pass' : 'fail', 'must be the https public domain — signed links, webhooks and pay pages are built from it');
        $this->row('APP_ENV', (string) config('app.env'), config('app.env') === 'production' ? 'pass' : 'warn');
        $this->row('APP_DEBUG', config('app.debug') ? 'true' : 'false', config('app.debug') ? 'fail' : 'pass', 'debug pages leak .env values — set APP_DEBUG=false');
        $this->row('Timezone', (string) config('app.timezone'), config('app.timezone') === 'Asia/Kolkata' ? 'pass' : 'fail');
        $this->row('Queue driver', (string) config('queue.default'), 'info', config('queue.default') === 'database' ? 'needs `php artisan queue:work` (supervisor) or jobs never run' : null);

        $this->section('Database');
        try {
            DB::connection()->getPdo();
            $this->row('Connection', DB::connection()->getDatabaseName(), 'pass');
            $ran = collect(DB::table('migrations')->pluck('migration'));
            $files = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getFilenameWithoutExtension());
            $pending = $files->diff($ran);
            $this->row('Pending migrations', (string) $pending->count(), $pending->isEmpty() ? 'pass' : 'fail', $pending->isEmpty() ? null : 'run: php artisan migrate --force  (' . $pending->take(3)->implode(', ') . (($pending->count() > 3) ? ', …' : '') . ')');
        } catch (\Throwable $e) {
            $this->row('Connection', 'FAILED', 'fail', $e->getMessage());
        }

        $this->section('Storage & PHP');
        // A symlink on Linux, a junction on Windows — either way the test that
        // matters is "can a file under storage/app/public be reached through it".
        $linkOk = file_exists(public_path('storage')) && is_dir(public_path('storage'));
        $this->row('public/storage link', $linkOk ? 'present' : 'missing', $linkOk ? 'pass' : 'fail', 'run: php artisan storage:link');
        $this->row('storage/ writable', is_writable(storage_path('app')) && is_writable(storage_path('logs')) ? 'yes' : 'NO', (is_writable(storage_path('app')) && is_writable(storage_path('logs'))) ? 'pass' : 'fail', 'chown -R <php-user> storage bootstrap/cache');
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        $need = array_values(array_intersect(['proc_open', 'exec', 'shell_exec', 'putenv', 'symlink'], $disabled));
        $this->row('Disabled PHP functions we need', $need ? implode(', ', $need) : 'none', $need ? 'fail' : 'pass', 'aaPanel → PHP → Disabled functions: remove these (voice TTS/STT, PDFs, storage:link)');
        $this->row('GD (image/QR)', extension_loaded('gd') ? 'loaded' : 'missing', extension_loaded('gd') ? 'pass' : 'fail');
        $this->row('upload_max_filesize', (string) ini_get('upload_max_filesize'), $this->bytes(ini_get('upload_max_filesize')) >= 8 * 1024 * 1024 ? 'pass' : 'warn', 'selfies/KYC uploads up to 8 MB — set 20M in aaPanel PHP config');
        $this->row('PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>=') ? 'pass' : 'fail');

        $this->section('Payments — Razorpay');
        $key = (string) config('services.razorpay.key');
        $this->row('RAZORPAY_KEY', $key === '' ? 'missing' : substr($key, 0, 12) . '…', $key === '' ? 'fail' : (str_starts_with($key, 'rzp_live_') ? 'pass' : 'warn'), $key === '' ? 'app checkout + Digi Market show NO payment step without it' : (str_starts_with($key, 'rzp_test_') ? 'TEST key — real customers cannot pay; switch to rzp_live_' : null));
        $this->row('RAZORPAY_SECRET', filled(config('services.razorpay.secret')) ? 'set' : 'missing', filled(config('services.razorpay.secret')) ? 'pass' : 'fail');
        $this->row('RAZORPAY_WEBHOOK_SECRET', filled(config('services.razorpay.webhook_secret')) ? 'set' : 'missing', filled(config('services.razorpay.webhook_secret')) ? 'pass' : 'warn', 'Razorpay dashboard → Webhooks → ' . url('/webhooks/razorpay') . ' (payment.captured, subscription.*)');

        $this->section('Zoom');
        $this->row('S2S OAuth (auto-create meetings)', app(\App\Services\Zoom\ZoomApiService::class)->configured() ? 'configured' : 'missing', app(\App\Services\Zoom\ZoomApiService::class)->configured() ? 'pass' : 'warn');
        $this->row('Meeting SDK (in-app join)', app(\App\Services\Zoom\ZoomSdkService::class)->configured() ? 'configured' : 'missing', app(\App\Services\Zoom\ZoomSdkService::class)->configured() ? 'pass' : 'warn');
        $this->row('Webhook secret', filled(config('services.zoom.webhook_secret')) ? 'set' : 'missing', filled(config('services.zoom.webhook_secret')) ? 'pass' : 'warn', 'details: php artisan zoom:check');

        $this->section('Messaging');
        // Effective creds = admin "Push Notification Settings" row first, .env fallback (PushSetting::fcm()).
        $fcmOn = app(\App\Services\Push\PushSender::class)->enabled();
        $this->row('FCM push', $fcmOn ? 'enabled' : 'off', $fcmOn ? 'pass' : 'fail', 'every ack/reminder/OTP-less notice is push-only — enable it: Admin → System → Push Notification Settings (FCM service account) or FCM_* in .env');
        $test = (string) config('services.whatsapp.test_recipient');
        $this->row('WHATSAPP_TEST_RECIPIENT', $test === '' ? 'cleared' : $test, $test === '' ? 'pass' : 'fail', 'every OTP/QR WhatsApp goes to this number instead of the customer — clear it in production');

        $this->section('L-BOX voice');
        $py = (string) config('lbox.stt.python');
        $sttOk = $py !== '' && ($py === 'python' || is_file($py));
        if ($sttOk && PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
            // The interpreter exists — but does it have faster-whisper? (/usr/bin/python3 usually doesn't; the venv does.)
            $probe = trim((string) @shell_exec(escapeshellarg($py) . ' -c "import faster_whisper; print(1)" 2>/dev/null'));
            $sttOk = $probe === '1';
        }
        $this->row('STT python (faster-whisper)', $py, $sttOk ? 'pass' : 'fail', 'LBOX_STT_PYTHON must be the venv python that has faster-whisper, e.g. /www/lbox-venv/bin/python3');
        $esp = (string) config('lbox.tts.espeak.bin');
        $this->row('espeak-ng', $esp, is_file($esp) ? 'pass' : 'warn', 'Tamil voice lines fall back to beeps/text — apt install -y espeak-ng, LBOX_ESPEAK_BIN=/usr/bin/espeak-ng');
        $this->row('LBOX_DEVICE_API_URL', (string) (config('lbox.device_api_url') ?: 'unset'), 'info', 'set to ' . url('/api/device/v1') . ' once every box is on fw >= 1.0.12 to pull the fleet to this server');

        $this->newLine();
        $this->failed
            ? $this->components->error('golive:check — FAILURES above must be fixed before customers use the app')
            : $this->components->info('golive:check — no failures');

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    protected function section(string $title): void
    {
        $this->newLine();
        $this->components->info($title);
    }

    protected function row(string $label, string $value, string $state, ?string $hint = null): void
    {
        $tag = match ($state) {
            'pass' => '<fg=green>PASS</>',
            'warn' => '<fg=yellow>WARN</>',
            'fail' => '<fg=red>FAIL</>',
            default => '<fg=gray>INFO</>',
        };
        if ($state === 'fail') {
            $this->failed = true;
        }
        $this->components->twoColumnDetail("{$tag} {$label}", $value);
        if ($hint && $state !== 'pass') {
            $this->line("       <fg=gray>→ {$hint}</>");
        }
    }

    protected function bytes(string $ini): int
    {
        $n = (int) $ini;

        return match (strtolower(substr(trim($ini), -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
