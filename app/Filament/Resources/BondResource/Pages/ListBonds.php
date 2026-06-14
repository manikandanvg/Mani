<?php

namespace App\Filament\Resources\BondResource\Pages;

use App\Filament\Resources\BondResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Bonds are created by the Sales (Billing) flow, never hand-entered here — so this list
 * has no "New bond" / "Register Purchase" buttons. It's the bond/contract register:
 * view contracts + QR, track expiry.
 */
class ListBonds extends ListRecords
{
    protected static string $resource = BondResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
