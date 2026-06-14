<?php

namespace App\Filament\Resources\LiveRateResource\Pages;

use App\Filament\Resources\LiveRateResource;
use App\Filament\Resources\LiveRateResource\Widgets\CurrencyRateFeedWidget;
use App\Services\Rates\ExchangeRateUpdater;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLiveRates extends ListRecords
{
    protected static string $resource = LiveRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('fetchFx')
                ->label('Fetch FX rates')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Fetch live exchange rates')
                ->modalDescription('Pulls the latest foreign-exchange rates from the configured provider and updates every active currency. Metal rates are not changed.')
                ->action(function () {
                    $result = app(ExchangeRateUpdater::class)->update();

                    Notification::make()
                        ->title($result['ok'] ? 'Exchange rates updated' : 'Could not update rates')
                        ->body($result['message'])
                        ->status($result['ok'] ? 'success' : 'warning')
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CurrencyRateFeedWidget::class,
        ];
    }
}
