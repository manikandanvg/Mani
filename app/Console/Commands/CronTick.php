<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Single cron entry point for the live server (board 2026-08-11) — attach ONE cron
 * line to this and everything runs: BV/GBV/rank recompute, GAP + CBC issuance
 * (month-idempotent: they only ever install once per calendar month, so a daily —
 * or hourly — cron can never double-pay), contract settlements, and FX rates.
 *
 *   0 3 * * *  cd /path/to/app && php artisan cron:run >> storage/logs/cron.log 2>&1
 *
 * Preferred over `schedule:run` on shared hosting where per-minute crons are not
 * available; on a VPS the standard Laravel scheduler works too and runs the same jobs.
 */
class CronTick extends Command
{
    protected $signature = 'cron:run';

    protected $description = 'Daily cron tick: recompute network, issue GAP/CBC (month-safe), settle contracts, fetch FX rates';

    public function handle(): int
    {
        $this->info('cron:run started ' . now()->toDateTimeString());

        $this->call('engine:run');          // recompute + gap + cbc + settlement (all idempotent)

        try {
            $this->call('rates:fetch');     // FX only; metal rates stay manual
        } catch (\Throwable $e) {
            // Never let a rate-provider outage fail the whole tick.
            $this->warn('rates:fetch failed: ' . $e->getMessage());
        }

        $this->rdRenewalReminders();
        $this->birthdayGreetings();
        $this->call('notify:meeting-reminders');   // fallback sweep for hosts with daily-only cron

        if (now()->day === 1) {
            $this->monthlyCutoffNotices();
        }

        // Safety net: drain anything a package queued (QUEUE_CONNECTION=database with
        // no worker) so no notification/job can strand in the jobs table.
        try {
            $this->call('queue:work', ['--stop-when-empty' => true, '--max-time' => 60]);
        } catch (\Throwable $e) {
            $this->warn('queue drain failed: ' . $e->getMessage());
        }

        $this->info('cron:run finished ' . now()->toDateTimeString());

        return self::SUCCESS;
    }

    /**
     * RD renewal reminder, 10 days ahead (board 2026-08-11). The contract chart puts
     * renewals on the 10th of each month; the next unpaid month = dues covered + 2
     * (month 1 was the joining payment). Runs daily, fires only on the exact
     * 10-days-before date, so each due is reminded exactly once.
     */
    protected function rdRenewalReminders(): void
    {
        $sent = 0;
        \App\Models\Bond::with('plan', 'member')
            ->where('status', 'active')
            ->whereHas('plan', fn ($q) => $q->where('type', 'rd'))
            ->chunkById(200, function ($bonds) use (&$sent) {
                foreach ($bonds as $bond) {
                    $validity = (int) ($bond->plan->validity_months ?: 11);
                    $covered = \App\Services\RdCollectionService::duesCovered($bond);
                    $nextMonthNo = $covered + 2;               // month 1 = joining
                    if ($nextMonthNo > $validity || ! $bond->bond_date) {
                        continue;                              // fully paid or no anchor
                    }
                    $dueDate = $bond->bond_date->copy()
                        ->addMonthsNoOverflow($nextMonthNo - 1)->startOfMonth()->day(10);

                    if (now()->addDays(10)->isSameDay($dueDate)) {
                        \App\Services\Push\Notifier::to($bond->member, 'rd',
                            'RD renewal due on ' . $dueDate->format('d M Y'),
                            'Your ' . ($bond->plan->code ?? 'savings') . ' instalment (due #' . ($nextMonthNo - 1)
                                . ') is due in 10 days. Pay at your branch or via auto-debit to keep your gold growing.',
                            route: '/contracts/' . $bond->id,
                        );
                        $sent++;
                    }

                    // Missed-instalment follow-up, exactly 3 days past the due date.
                    if (now()->subDays(3)->isSameDay($dueDate)) {
                        \App\Services\Push\Notifier::to($bond->member, 'rd',
                            'RD instalment missed — due was ' . $dueDate->format('d M Y'),
                            'We haven\'t received due #' . ($nextMonthNo - 1) . ' of your '
                                . ($bond->plan->code ?? 'savings scheme') . '. Pay at your branch to keep your benefits on track.',
                            route: '/contracts/' . $bond->id,
                        );
                        $sent++;
                    }
                }
            });
        $this->line("  RD reminders sent: {$sent}");
    }

    /** Birthday greetings — a jeweller never forgets a birthday (board 2026-08-11). */
    protected function birthdayGreetings(): void
    {
        $sent = 0;
        \App\Models\Member::where('status', 'active')
            ->whereNotNull('dob')
            ->whereMonth('dob', now()->month)
            ->whereDay('dob', now()->day)
            ->chunkById(200, function ($members) use (&$sent) {
                foreach ($members as $member) {
                    \App\Services\Push\Notifier::to($member, 'news',
                        'Happy birthday, ' . ($member->name ?: 'from Lord Jeweller') . '! 🎉',
                        'The whole Lord Jeweller family wishes you a golden year ahead. Visit your branch for a special birthday welcome.',
                    );
                    $sent++;
                }
            });
        $this->line("  birthday greetings: {$sent}");
    }

    /** Monthly cutoff notice (board 2026-08-11): tell earners their month has been processed. */
    protected function monthlyCutoffNotices(): void
    {
        $memberIds = \App\Models\CommissionLedger::whereYear('earned_on', now()->year)
            ->whereMonth('earned_on', now()->month)
            ->distinct()->pluck('member_id')
            ->merge(\App\Models\CbcEntry::whereYear('cbc_date', now()->year)
                ->whereMonth('cbc_date', now()->month)->distinct()->pluck('member_id'))
            ->unique();

        $sent = 0;
        foreach (\App\Models\Member::whereIn('id', $memberIds)->get() as $member) {
            \App\Services\Push\Notifier::to($member, 'commission',
                'Monthly cutoff processed',
                'Your ' . now()->format('F Y') . ' commission installments have been generated and are pending approval. Check Earnings for details.',
                route: '/earnings',
            );
            $sent++;
        }
        $this->line("  monthly cutoff notices: {$sent}");
    }
}
