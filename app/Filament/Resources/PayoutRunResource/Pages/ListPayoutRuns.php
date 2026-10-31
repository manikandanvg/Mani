<?php

namespace App\Filament\Resources\PayoutRunResource\Pages;

use App\Filament\Resources\PayoutRunResource;
use Filament\Resources\Pages\ListRecords;

class ListPayoutRuns extends ListRecords
{
    protected static string $resource = PayoutRunResource::class;

    protected ?string $subheading = 'Monthly cut-off runs (php artisan payout:cutoff --cutoff=YYYY-MM-DD). Generated = GAP rows pending on Commission Approval; Approve run credits the cash wallets.';
}
