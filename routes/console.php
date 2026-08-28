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

// Monthly Tasks engine (board 2026-08-29). Order on the 1st matters: LOCK last month's
// scores (02:40) BEFORE gap (03:00) reads them; then ROLL the new month (02:50).
Schedule::command('tasks:run --only=lock')->monthlyOn(1, '02:40')->withoutOverlapping();
Schedule::command('tasks:run --only=roll')->monthlyOn(1, '02:50')->withoutOverlapping();
Schedule::command('tasks:run --only=measure')->dailyAt('02:30')->withoutOverlapping();
// 8 PM: branches that never tapped close are closed; stock vs Opening snapshot for the day.
Schedule::command('tasks:run --only=close')->dailyAt('20:05')->withoutOverlapping();
// NOTE: --only=statements is deliberately NOT scheduled. The payout-statement system is
// retired; the manual admin/commission-approval gate is the only path that releases
// earnings, and the statements job would silently flip pending ledger rows to approved.

// Live foreign-exchange rates (currencies.rate_to_base). Needs the OS scheduler running
// `schedule:run`; until then use the "Fetch FX rates" button on admin → Live Rates.
Schedule::command('rates:fetch')->dailyAt('06:00')->withoutOverlapping();

// "Meeting starting soon" pushes — idempotent via meetings.reminder_sent_at.
Schedule::command('notify:meeting-reminders')->everyFifteenMinutes()->withoutOverlapping();
// Verified minutes safety net (2026-08-25): reconcile ended Zoom meetings with
// Zoom's participant report in case the participant webhooks did not arrive.
Schedule::command('zoom:sync-attendance')->everyThirtyMinutes()->withoutOverlapping();
