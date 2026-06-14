<?php

namespace App\Filament\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Carbon;
use League\Csv\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Generic, table-agnostic export toolbar — CSV / Excel / PDF / Print — injected into EVERY
 * Filament table via Table::configureUsing() (see AppServiceProvider). It reads the table's
 * own columns and the currently filtered+sorted rows, so it always matches what the user
 * sees. No per-resource configuration needed.
 */
class TableExport
{
    /** The header ActionGroup added to every table. */
    public static function actions(): array
    {
        return [
            ActionGroup::make([
                Action::make('export_csv')->label('CSV')->icon('heroicon-m-table-cells')
                    ->action(fn ($livewire) => static::csv($livewire)),
                Action::make('export_xlsx')->label('Excel')->icon('heroicon-m-document-chart-bar')
                    ->action(fn ($livewire) => static::xlsx($livewire)),
                Action::make('export_pdf')->label('PDF')->icon('heroicon-m-document-arrow-down')
                    ->action(fn ($livewire) => static::pdf($livewire, false)),
                Action::make('export_print')->label('Print')->icon('heroicon-m-printer')
                    ->action(fn ($livewire) => static::pdf($livewire, true)),
            ])
                ->label('Export')
                ->icon('heroicon-m-arrow-down-tray')
                ->button()
                ->color('gray'),
        ];
    }

    /** Build [headers[], rows[][], title] from the live (filtered/sorted) table. */
    protected static function data($livewire): array
    {
        $table = $livewire->getTable();

        /** @var Column[] $columns */
        $columns = collect($table->getColumns())
            ->reject(fn (Column $c) => $c instanceof ImageColumn || $c->isHidden())
            ->values();

        $headers = $columns->map(fn (Column $c) => (string) $c->getLabel())->all();

        $records = $livewire->getFilteredSortedTableQuery()->limit(5000)->get();

        $rows = $records->map(fn ($record) => $columns
            ->map(fn (Column $c) => static::cell($c, $record))
            ->all())->all();

        return [$headers, $rows, static::title($livewire)];
    }

    /** Resolve one cell's display value defensively (mirrors the column's own state). */
    protected static function cell(Column $column, $record): string
    {
        try {
            $state = $column->record($record)->getState();
        } catch (\Throwable) {
            return '';
        }
        if (is_bool($state)) {
            return $state ? 'Yes' : 'No';
        }
        if ($state instanceof Carbon) {
            return $state->toDayDateTimeString();
        }
        if (is_array($state)) {
            return collect($state)->map(fn ($v) => is_scalar($v) ? $v : json_encode($v))->implode(', ');
        }

        return $state === null ? '' : (string) $state;
    }

    protected static function title($livewire): string
    {
        $name = method_exists($livewire, 'getTitle') ? (string) $livewire->getTitle() : '';

        return $name !== '' ? $name : 'Export';
    }

    protected static function filename($livewire, string $ext): string
    {
        $slug = \Illuminate\Support\Str::slug(static::title($livewire)) ?: 'export';

        return $slug . '-' . now()->format('Ymd-His') . '.' . $ext;
    }

    // --- formats ---

    protected static function csv($livewire)
    {
        [$headers, $rows] = static::data($livewire);
        $csv = Writer::createFromString();
        $csv->insertOne($headers);
        $csv->insertAll($rows);

        return response()->streamDownload(
            fn () => print($csv->toString()),
            static::filename($livewire, 'csv'),
            ['Content-Type' => 'text/csv'],
        );
    }

    protected static function xlsx($livewire)
    {
        [$headers, $rows] = static::data($livewire);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return response()->download($tmp, static::filename($livewire, 'xlsx'))->deleteFileAfterSend();
    }

    /**
     * PDF download, or inline ("Print"). dompdf's own download()/stream() return a plain
     * Response whose binary body breaks Livewire's JSON layer ("malformed UTF-8") — so we
     * hand back a StreamedResponse, which Livewire correctly treats as a file download.
     */
    protected static function pdf($livewire, bool $print)
    {
        // dompdf font parsing is memory-hungry; a wide/long table can exceed the default
        // 128M and 500. Lift the ceiling for this request so the export never crashes.
        @ini_set('memory_limit', '512M');

        [$headers, $rows, $title] = static::data($livewire);

        $output = Pdf::setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->loadView('pdf.table', [
                'headers' => $headers,
                'rows' => $rows,
                'title' => $title,
                'generatedAt' => now()->toDayDateTimeString(),
            ])
            ->setPaper('a4', 'landscape')
            ->output();

        return response()->streamDownload(
            fn () => print($output),
            static::filename($livewire, 'pdf'),
            ['Content-Type' => 'application/pdf'],
            $print ? 'inline' : 'attachment',
        );
    }
}
