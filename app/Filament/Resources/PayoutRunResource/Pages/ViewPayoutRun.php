<?php

namespace App\Filament\Resources\PayoutRunResource\Pages;

use App\Filament\Resources\PayoutRunResource;
use App\Models\PayoutRun;
use App\Services\Payout\CutoffService;
use App\Support\Money;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPayoutRun extends ViewRecord
{
    protected static string $resource = PayoutRunResource::class;

    public function getTitle(): string
    {
        /** @var PayoutRun $r */
        $r = $this->record;

        return 'Payout run ' . $r->label();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approveRun')
                ->label('Approve run')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => PayoutRunResource::canApprove($this->record))
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Approve the ' . $this->record->label() . ' cut-off?')
                ->modalDescription(fn () => 'Credits every pending GAP row (' . number_format($this->record->gap_rows) . ' rows, gross ' . Money::inr((float) $this->record->gap_gross) . ') to the members\' CASH wallets net of TDS + service charge, and passes the ' . number_format($this->record->cbc_rows) . ' CBC coupons due. Cannot be undone.')
                ->action(function () {
                    $r = app(CutoffService::class)->approve($this->record, auth()->id());
                    Notification::make()->success()->title('Run ' . $this->record->label() . ' approved')
                        ->body($r['gap'] . ' GAP rows (gross ' . Money::inr($r['gross']) . ') for ' . $r['members'] . ' members and ' . $r['cbc'] . ' CBC coupons credited.')->send();
                    $this->refreshFormData(['status', 'approved_at']);
                }),
            Actions\Action::make('memberSummary')
                ->label('GAP log (xlsx)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(fn () => PayoutRunResource::summaryDownload($this->record)),
            Actions\Action::make('downloadLog')
                ->label('Log file')->icon('heroicon-o-document-text')->color('gray')
                ->visible(fn () => $this->record->log_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($this->record->log_path))
                ->action(fn () => response()->download(\Illuminate\Support\Facades\Storage::disk('local')->path($this->record->log_path))),
        ];
    }
}
