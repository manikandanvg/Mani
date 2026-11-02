<?php

namespace App\Console\Commands;

use App\Services\Phone\MissedCallVerification;
use Illuminate\Console\Command;

/**
 *   php artisan exotel:check
 *
 * Confirms the Exotel credentials in .env, lists the Exophones on the account, and prints the
 * Passthru URL to paste into the Exophone's call flow.
 */
class ExotelCheck extends Command
{
    protected $signature = 'exotel:check';

    protected $description = 'Verify the Exotel credentials, list the virtual numbers and print the webhook URL';

    public function handle(MissedCallVerification $svc): int
    {
        $c = config('services.exotel');
        $this->line('Account SID: ' . ($c['sid'] ?: '—') . ' · region host: ' . ($c['subdomain'] ?: 'api.exotel.com'));
        if (! filled($c['sid']) || ! filled($c['api_key']) || ! filled($c['api_token'])) {
            $this->error('EXOTEL_SID / EXOTEL_API_KEY / EXOTEL_API_TOKEN missing in .env');

            return self::FAILURE;
        }
        try {
            $numbers = $svc->exophones();
        } catch (\Throwable $e) {
            $this->error('Exotel refused the credentials: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->info('Credentials OK. Exophones on the account: ' . count($numbers));
        foreach ($numbers as $n) {
            $this->line(sprintf('  %-16s %s', $n['number'] ?? '?', $n['friendly'] ?? ''));
        }
        if (! $numbers) {
            $this->warn('No Exophone yet — buy one in the Exotel dashboard (ExoPhones), then set EXOTEL_VIRTUAL_NUMBER.');
        }
        $this->line('EXOTEL_VIRTUAL_NUMBER = ' . ($c['virtual_number'] ?: '— not set'));
        $this->line('Passthru URL for the Exophone flow: ' . rtrim((string) config('app.url'), '/') . '/webhooks/exotel/missed-call?key=' . ($c['webhook_key'] ? '<EXOTEL_WEBHOOK_KEY>' : '— set EXOTEL_WEBHOOK_KEY'));
        $this->line(MissedCallVerification::configured() ? 'Missed-call verification is READY.' : 'Not ready yet: set the missing values above.');

        return self::SUCCESS;
    }
}
