<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly MLM engine (replaces the legacy URL/CLI cron chain; not web-reachable).
Schedule::command('engine:run --only=recompute')->dailyAt('02:00')->withoutOverlapping();
// GAP + CBC are MONTHLY installments — issued on the 1st like the legacy Ascript,
// but automated. The engine is also month-idempotent, so a manual re-run
// (`engine:run --only=gap`) or an extra scheduler firing can never double-issue.
Schedule::command('engine:run --only=gap')->monthlyOn(1, '03:00')->withoutOverlapping();
Schedule::command('engine:run --only=cbc')->monthlyOn(1, '03:30')->withoutOverlapping();
Schedule::command('engine:run --only=settlement')->dailyAt('04:00')->withoutOverlapping();
// NOTE: --only=statements is deliberately NOT scheduled. The payout-statement system is
// retired; the manual admin/commission-approval gate is the only path that releases
// earnings, and the statements job would silently flip pending ledger rows to approved.

// Live foreign-exchange rates (currencies.rate_to_base). Needs the OS scheduler running
// `schedule:run`; until then use the "Fetch FX rates" button on admin → Live Rates.
Schedule::command('rates:fetch')->dailyAt('06:00')->withoutOverlapping();

// "Meeting starting soon" pushes — idempotent via meetings.reminder_sent_at.
Schedule::command('notify:meeting-reminders')->everyFifteenMinutes()->withoutOverlapping();
