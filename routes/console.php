<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly MLM engine (replaces the legacy URL/CLI cron chain; not web-reachable).
Schedule::command('engine:run --only=recompute')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('engine:run --only=gap')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('engine:run --only=cbc')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('engine:run --only=settlement')->dailyAt('04:00')->withoutOverlapping();
// NOTE: --only=statements is deliberately NOT scheduled. The payout-statement system is
// retired; the manual admin/commission-approval gate is the only path that releases
// earnings, and the statements job would silently flip pending ledger rows to approved.

// Live foreign-exchange rates (currencies.rate_to_base). Needs the OS scheduler running
// `schedule:run`; until then use the "Fetch FX rates" button on admin → Live Rates.
Schedule::command('rates:fetch')->dailyAt('06:00')->withoutOverlapping();
