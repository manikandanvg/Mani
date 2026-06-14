<?php

namespace App\Console\Commands;

use App\Services\Rates\ExchangeRateUpdater;
use Illuminate\Console\Command;

class FetchRates extends Command
{
    protected $signature = 'rates:fetch';

    protected $description = 'Fetch live foreign-exchange rates and update each currency\'s exchange rate';

    public function handle(ExchangeRateUpdater $updater): int
    {
        $result = $updater->update();

        $this->line($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
