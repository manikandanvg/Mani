<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Models\Expense;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('summaryPdf')->label('Summary PDF')->icon('heroicon-m-document-arrow-down')->color('gray')
                ->action(fn () => $this->summaryPdf()),
            Actions\CreateAction::make()->label('Submit expense'),
        ];
    }

    /** The listed rows (tab + filters + search) month by month, head by head — fits a PDF. */
    public function summaryPdf()
    {
        @ini_set('memory_limit', '512M');   // dompdf font parsing, as in TableExport
        @set_time_limit(300);
        $s = ExpenseResource::summary($this->getFilteredTableQuery());
        $tab = $this->getTabs()[$this->activeTab ?? 'all'] ?? null;
        $title = 'Expense ledger — summary (' . ($tab?->getLabel() ?? 'All') . ') · Approved Rs ' . Money::group($s['approved'])
            . ' · Waiting for approval Rs ' . Money::group($s['waiting']);

        $output = Pdf::setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'], true)
            ->loadView('pdf.table', [
                'headers' => ['Month', 'Kind', 'Ledger', 'Rows', 'Amount (Rs)'],
                'rows' => $s['rows'],
                'title' => $title,
                'generatedAt' => now()->toDayDateTimeString(),
            ])
            ->setPaper('a4', 'portrait')
            ->output();

        return response()->streamDownload(fn () => print($output), 'expense-summary-' . now()->format('Ymd-His') . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** Board 2026-11-27: the auditor files two reports — the expenses and the capital assets. */
    public function getTabs(): array
    {
        $assets = array_keys(Expense::ASSET_CATEGORIES);

        return [
            'all' => Tab::make('All'),
            // NB: the parameter MUST be named $query — Filament injects it by name.
            'expenses' => Tab::make('Expenses')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('category', $assets)),
            'assets' => Tab::make('Capital assets')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('category', $assets)),
            'waiting' => Tab::make('Waiting for approval')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Expense::SUBMITTED)),
        ];
    }
}
