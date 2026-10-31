<?php

namespace App\Console\Commands;

use App\Models\PushSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Load the Firebase service-account key (the JSON downloaded from Firebase console →
 * Project settings → Service accounts) into System → Push Notification Settings.
 *
 * The legacy CodeIgniter app ships the same key at
 *   application/third_party/firebase/lordicl-firebase-adminsdk-dtmiu-11cc3288b8.json
 * (project "lordicl") — the Android app already registers with that Firebase project, so
 * the new server must push through the same one.
 *
 *   php artisan push:import-fcm /www/wwwroot/lordicl/application/third_party/firebase/lordicl-firebase-adminsdk-dtmiu-11cc3288b8.json
 */
class PushImportFcm extends Command
{
    protected $signature = 'push:import-fcm {file : Firebase service-account JSON} {--disable : Import the key but leave FCM switched off}';

    protected $description = 'Import a Firebase service-account JSON into Push Notification Settings (FCM)';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }
        $j = json_decode((string) file_get_contents($file), true);
        if (! is_array($j) || empty($j['project_id']) || empty($j['client_email']) || empty($j['private_key'])) {
            $this->error('Not a Firebase service-account JSON (needs project_id, client_email, private_key).');

            return self::FAILURE;
        }

        $s = PushSetting::current();
        $s->fill([
            'fcm_project_id' => $j['project_id'],
            'fcm_client_email' => $j['client_email'],
            'fcm_private_key' => $j['private_key'],
            'fcm_enabled' => ! $this->option('disable'),
        ])->save();
        Cache::forget('fcm.access_token');

        $this->info("FCM credentials saved: project {$j['project_id']} · {$j['client_email']} · FCM " . ($s->fcm_enabled ? 'ENABLED' : 'disabled'));
        $this->line('Verify with System → Push Notification Settings → "Send a test notification".');

        return self::SUCCESS;
    }
}
