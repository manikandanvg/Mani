<?php

namespace App\Filament\Resources\PayoutRunResource\Pages;

use App\Filament\Resources\PayoutRunResource;
use App\Models\PayoutRun;
use App\Services\Payout\CutoffService;
use App\Support\Money;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListPayoutRuns extends ListRecords
{
    protected static string $resource = PayoutRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Go-live 2026-11-01: the monthly cut-off from the screen, not only the console.
            // Same engine as `payout:cutoff` (legacy Ascript parity): PENDING rows + a run
            // record + the log; nothing reaches a wallet until "Approve run". The scheduler
            // also fires it on the 1st — the engine is bond-per-month idempotent, so a second
            // run for a month that already has one is refused here instead of making a twin.
            Actions\Action::make('generate')
                ->label('Generate cut-off')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible(fn () => (bool) auth()->user()?->isSuperAdmin())
                ->form([
                    Forms\Components\DatePicker::make('cutoff')
                        ->label('Cut-off date (contracts up to and including)')
                        ->default(fn () => Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString())
                        ->maxDate(fn () => Carbon::today())
                        ->required()
                        ->native(false)
                        ->helperText('Normally the last day of the month that just ended. The pay month is the month after it.'),
                    // Board 2026-11-01: a static password so a stray click never generates a run.
                    Forms\Components\TextInput::make('password')
                        ->label('Cut-off password')
                        ->password()->revealable()
                        ->required()
                        ->autocomplete('off')
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            $expected = (string) config('services.payout.cutoff_password');
                            if ($expected === '') {
                                $fail('No cut-off password is configured (PAYOUT_CUTOFF_PASSWORD in .env).');
                            } elseif (! hash_equals($expected, (string) $value)) {
                                $fail('Wrong cut-off password.');
                            }
                        })
                        ->validationAttribute('cut-off password'),
                    Forms\Components\Checkbox::make('confirm')
                        ->label('I confirm: generate the monthly cut-off for this date (pending rows, approved separately).')
                        ->accepted()
                        ->validationMessages(['accepted' => 'Tick the confirmation to continue, or Cancel.']),
                ])
                ->modalHeading('Generate the monthly cut-off?')
                ->modalDescription('Computes every contract\'s GAP level amounts and the CBC coupons due as PENDING rows (about a minute). Review the run, then approve it to credit the cash wallets.')
                ->modalSubmitActionLabel('Generate')
                ->action(function (array $data) {
                    $date = Carbon::parse($data['cutoff'])->startOfDay();
                    $period = CutoffService::periodFor($date);
                    if ($existing = PayoutRun::whereDate('period', $period->toDateString())->first()) {
                        Notification::make()->warning()
                            ->title('A run for ' . $existing->label() . ' already exists (#' . $existing->id . ')')
                            ->body('Open it to review or approve. A cut-off month is generated once.')
                            ->send();

                        return;
                    }

                    $run = app(CutoffService::class)->run($date, ['ep_cap' => false, 'task_factor' => false, 'dry_run' => false], null, auth()->id());

                    Notification::make()->success()
                        ->title('Run ' . $run->label() . ' generated (#' . $run->id . ')')
                        ->body(number_format($run->gap_rows) . ' GAP rows for ' . number_format($run->gap_members) . ' members, gross '
                            . Money::inr((float) $run->gap_gross) . '; ' . number_format($run->cbc_rows) . ' CBC coupons due ('
                            . Money::inr((float) $run->cbc_worth) . '). Review, then Approve run.')
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
