<?php

namespace App\Filament\Resources\PayrollRunResource\Pages;

use App\Filament\Resources\PayrollRunResource;
use App\Services\Payroll\PayrollService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPayrollRuns extends ListRecords
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate')
                ->label('Generate payroll')
                ->icon('heroicon-o-calculator')
                ->form([
                    Forms\Components\Select::make('year')
                        ->options(array_combine($y = range(now()->year - 1, now()->year + 1), $y))
                        ->default(now()->year)->required(),
                    Forms\Components\Select::make('month')
                        ->options(array_combine(range(1, 12), array_map(
                            fn ($m) => \Illuminate\Support\Carbon::create(null, $m, 1)->format('F'), range(1, 12),
                        )))
                        ->default(now()->month)->required(),
                ])
                ->modalDescription('Builds (or rebuilds) the draft run for the month from attendance records. Approved runs are never touched.')
                ->action(function (array $data) {
                    try {
                        $run = app(PayrollService::class)->generate((int) $data['year'], (int) $data['month'], auth()->id());
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()
                        ->title("Payroll {$run->periodLabel()} generated — {$run->payslips()->count()} payslip(s)")
                        ->success()->send();
                }),
        ];
    }
}
