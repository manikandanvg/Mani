<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly MLM engine (replaces the legacy URL/CLI cron chain; not web-reachable).
Schedule::command('engine:run --only=recompute')->dailyAt('02:00')->withoutOverlapping();
// Monthly cut-off (board 2026-10-31, legacy Ascript parity): the bond's stored GAP level
// amounts + the CBC coupons due, written as PENDING rows under Commissions → Payout Runs for
// the month that just ended (default cut-off = last day of the previous month). HQ reviews and
// approves on that screen — nothing reaches a wallet here. Bond-per-month idempotent, so the
// "Generate cut-off" button or a console re-run can never double-issue. The old
// `engine:run --only=gap|cbc` steps are RETIRED from the schedule (they priced GAP from plan %
// and minted CBC rows, which would clash with the legacy coupon schedule carried over).
Schedule::command('payout:cutoff --quiet-log')->monthlyOn(1, '03:00')->withoutOverlapping();
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

// Play Store reviews → Website → Testimonials (board 2026-10-31 item 14). Google serves
// only the last 7 days of reviews, so a daily pull keeps the set complete. No-op until
// GOOGLE_PLAY_SERVICE_ACCOUNT_JSON is configured.
Schedule::command('testimonials:import-play-reviews')->dailyAt('05:10')->withoutOverlapping();

// QUEUE_CONNECTION=database with no supervisor worker (go-live 2026-11-02): drain whatever a
// package queued (notifications, pushes) every minute from the scheduler itself, so a
// per-minute `schedule:run` cron is the only process the server needs.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping();

// Activity Log (board 2026-10-31): keep one year.
Schedule::command('activity:prune --days=365')->monthlyOn(2, '03:50')->withoutOverlapping();
