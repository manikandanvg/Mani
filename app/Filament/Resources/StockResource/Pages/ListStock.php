<?php

namespace App\Filament\Resources\StockResource\Pages;

use App\Filament\Resources\StockResource;
use App\Models\Stock;
use App\Support\Translatable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStock extends ListRecords
{
    protected static string $resource = StockResource::class;

    protected static ?string $title = 'Stock';

    /**
     * Board 2026-10-31 (common 2): branches hand in their opening stock as an Excel sheet.
     * "Download template" gives the wide sheet (one row per branch, one column per product,
     * current stock pre-filled); "Import opening stock" loads the filled sheet back.
     */
    protected function getHeaderActions(): array
    {
        $dealer = (bool) auth()->user()?->isDistributor();
        $branchId = $dealer ? (int) auth()->user()->branch_id : null;

        return [
            \Filament\Actions\Action::make('openingTemplate')
                ->label('Download opening-stock template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () use ($branchId) {
                    $path = tempnam(sys_get_temp_dir(), 'opening') . '.xlsx';
                    app(\App\Services\Stock\OpeningStockImporter::class)->template($path, $branchId);

                    return response()->download($path, 'opening-stock-' . now()->format('Ymd') . '.xlsx')->deleteFileAfterSend();
                }),
            \Filament\Actions\Action::make('importOpening')
                ->label('Import opening stock')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('Import opening stock from Excel')
                ->modalDescription('Upload the filled template (or a sheet with Branch / Product / Qty columns). Each listed cell SETS the branch stock to that number of pieces and records an opening movement; blank cells are left alone. Tick "Check only" first to see what would change.')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('file')
                        ->label('Excel / CSV file')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'application/vnd.ms-excel', 'text/plain'])
                        ->directory('imports')->visibility('private')->required()
                        ->storeFileNamesIn('file_name'),
                    \Filament\Forms\Components\Toggle::make('dry_run')->label('Check only (do not write)')->default(true),
                ])
                ->action(function (array $data) use ($branchId) {
                    $rel = $data['file'];
                    $path = \Illuminate\Support\Facades\Storage::disk('local')->path($rel);
                    if (! is_file($path)) {
                        $path = \Illuminate\Support\Facades\Storage::disk('public')->path($rel);
                    }
                    $imp = app(\App\Services\Stock\OpeningStockImporter::class)
                        ->import($path, (bool) $data['dry_run'], $branchId, auth()->id());

                    $lines = collect($imp->changes)->take(15)
                        ->map(fn ($c) => "• {$c['branch']} · {$c['product']}: {$c['from']} → {$c['to']}")->implode('<br>');
                    $errors = collect($imp->errors)->take(10)->map(fn ($e) => "Row {$e['row']}: {$e['message']}")->implode('<br>');

                    Notification::make()
                        ->title(($data['dry_run'] ? 'Check: ' : 'Imported: ') . $imp->applied . ' stock line(s)'
                            . ($imp->errors ? ' · ' . count($imp->errors) . ' error(s)' : ''))
                        ->body(trim($lines . ($imp->changes && count($imp->changes) > 15 ? '<br>…' : '') . ($errors ? '<br><b>' . $errors . '</b>' : '')) ?: 'Nothing to change.')
                        ->{$imp->errors ? 'warning' : ($data['dry_run'] ? 'info' : 'success')}()
                        ->persistent()->send();
                }),
        ];
    }

    /**
     * Low-stock alert (board 2026-08-13): when any product in the visible scope has
     * fallen to or below its opening level, a danger notification pops up naming the worst
     * offenders, with a link that filters the table down to just those rows.
     */
    public function mount(): void
    {
        parent::mount();

        $default = Translatable::defaultLocale();

        // Same branch scoping the table applies, so a dealer only sees their own.
        $low = static::getResource()::getEloquentQuery()
            ->low()
            ->with(['branch', 'catalogProduct'])
            ->orderBy('quantity')
            ->limit(6)
            ->get();

        if ($low->isEmpty()) {
            return;
        }

        $total = static::getResource()::getEloquentQuery()->low()->count();

        $lines = $low->map(function (Stock $s) use ($default) {
            $name = $s->catalogProduct
                ? Translatable::pick($s->catalogProduct->name, $default)
                : ($s->catalogProduct?->code ?? 'Item');

            return sprintf(
                '• %s — %s left (opening %s)%s',
                $name,
                rtrim(rtrim(number_format((float) $s->quantity, 4), '0'), '.'),
                rtrim(rtrim(number_format((float) $s->min_qty, 4), '0'), '.'),
                $s->branch ? ' · ' . $s->branch->name : '',
            );
        })->implode('<br>');

        Notification::make()
            ->title($total === 1 ? '1 product is at its opening stock' : "{$total} products are at opening stock")
            ->body($lines . ($total > $low->count() ? '<br><em>…and ' . ($total - $low->count()) . ' more</em>' : ''))
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->persistent()
            ->send();
    }
}
