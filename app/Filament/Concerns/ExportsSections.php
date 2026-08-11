<?php

namespace App\Filament\Concerns;

use Barryvdh\DomPDF\Facade\Pdf;
use League\Csv\Writer;

/**
 * CSV / Excel / PDF / Print exports for pages that render $this->sections
 * (Track screens, Reports — board 2026-08-11). Exports exactly what is on
 * screen: every section's key-values and table rows.
 */
trait ExportsSections
{
    protected function exportSlug(): string
    {
        return (string) str(static::class)->classBasename()->kebab();
    }

    protected function exportSections(): array
    {
        if (empty($this->sections) && method_exists($this, 'run')) {
            $this->run();
        }
        if (empty($this->sections) && method_exists($this, 'lookup')) {
            $this->lookup();
        }

        return $this->sections;
    }

    public function exportCsv()
    {
        $sections = $this->exportSections();
        $slug = $this->exportSlug();

        return response()->streamDownload(function () use ($sections) {
            $csv = Writer::createFromString();
            foreach ($sections as $section) {
                if (! empty($section['heading'])) {
                    $csv->insertOne([$section['heading']]);
                }
                foreach ($section['kv'] ?? [] as $label => $value) {
                    $csv->insertOne([$label, (string) $value]);
                }
                if (! empty($section['columns'])) {
                    $csv->insertOne($section['columns']);
                    $csv->insertAll($section['rows'] ?? []);
                }
                $csv->insertOne([]);
            }
            echo $csv->toString();
        }, $slug . '-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportXlsx()
    {
        $sections = $this->exportSections();
        $slug = $this->exportSlug();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile($path);
        foreach ($sections as $section) {
            if (! empty($section['heading'])) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([$section['heading']]));
            }
            foreach ($section['kv'] ?? [] as $label => $value) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([$label, (string) $value]));
            }
            if (! empty($section['columns'])) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($section['columns']));
                foreach ($section['rows'] ?? [] as $row) {
                    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_map(fn ($v) => (string) $v, $row)));
                }
            }
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['']));
        }
        $writer->close();

        return response()->download($path, $slug . '-' . now()->format('Ymd-His') . '.xlsx')->deleteFileAfterSend();
    }

    public function exportPdf()
    {
        return $this->sectionsPdf(false);
    }

    public function exportPrint()
    {
        return $this->sectionsPdf(true);
    }

    protected function sectionsPdf(bool $inline)
    {
        @ini_set('memory_limit', '512M');
        $sections = $this->exportSections();
        $slug = $this->exportSlug();

        $output = Pdf::setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->loadView('pdf.sections', [
                'title' => property_exists($this, 'title') && static::$title ? static::$title : str($slug)->headline(),
                'sections' => $sections,
                'generatedAt' => now()->toDayDateTimeString(),
            ])
            ->setPaper('a4', 'landscape')
            ->output();

        return response()->streamDownload(
            fn () => print($output),
            $slug . '-' . now()->format('Ymd-His') . '.pdf',
            ['Content-Type' => 'application/pdf'],
            $inline ? 'inline' : 'attachment',
        );
    }
}
