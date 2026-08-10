<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Filament\Resources\SupportTicketResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            // NB: the parameter MUST be named $query — Filament injects it by name,
            // and a mis-named param silently receives a model-less Builder instead.
            'open' => Tab::make(__('Open'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'open')),
            'closed' => Tab::make(__('Closed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'open';
    }
}
