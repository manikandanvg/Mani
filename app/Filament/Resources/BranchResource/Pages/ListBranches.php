<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Resources\BranchResource;
use App\Services\Geo\BranchPincodeFiller;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBranches extends ListRecords
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Board 2026-10-31 (common 1): pincode is the key of the network hierarchy —
            // fill the missing ones from the address / city against the pincode master.
            Actions\Action::make('fillPincodes')
                ->label('Auto-fill missing pincodes')
                ->icon('heroicon-o-map-pin')
                ->color('gray')
                ->visible(fn () => ! auth()->user()?->isDistributor())
                ->requiresConfirmation()
                ->modalHeading('Auto-fill missing pincodes')
                ->modalDescription('For every branch without a pincode: a 6-digit PIN found in the address is used as-is; otherwise the city / address is matched against the pincode master. Branches that cannot be resolved stay empty — use the "Missing pincode" filter + Export to get the Excel list.')
                ->action(function () {
                    $r = app(BranchPincodeFiller::class)->fillAll();
                    Notification::make()->success()
                        ->title("Pincodes filled: {$r['filled']} · still missing: {$r['missing']}")
                        ->body($r['missing'] > 0 ? 'Filter the table by "Missing pincode" and export to Excel for the rest.' : 'Every branch now has a pincode.')
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
