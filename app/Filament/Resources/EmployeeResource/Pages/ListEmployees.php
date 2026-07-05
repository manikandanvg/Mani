<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Services\Payroll\EmployeeService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // One-click mass enrolment: every active distributor holding a TBP stage
            // (depth ≥ 1) without a profile gets one, defaults copied from the stage.
            Actions\Action::make('syncFromRanks')
                ->label('Enrol from TBP stages')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('Enrol every active distributor holding a TBP stage who is not yet an employee. Existing profiles are untouched.')
                ->action(function () {
                    $count = app(EmployeeService::class)->syncFromRanks();
                    Notification::make()
                        ->title($count > 0 ? "{$count} distributor(s) enrolled" : 'Everyone eligible is already enrolled')
                        ->success()->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
