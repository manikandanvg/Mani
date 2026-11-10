<?php

namespace App\Console\Commands;

use App\Support\Gst\GstAuditorReport;
use App\Support\Gst\GstAuditorWorkbook;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Auditor GST registers from the command line (same engine as System → Report → GST Report):
 *
 *   php artisan gst:auditor-export --month=2026-10 --source=legacy --out="C:\...\Oct\gst"
 *   php artisan gst:auditor-export --from=2026-11-01 --to=2026-11-30 --b2c-start=3089 --b2b-start=5747 --record
 *
 * Writes "GSTR REPORT B2C <month>.xlsx", "GSTR REPORT B2B <month>.xlsx" and "GSTR1 <month>.xlsx".
 * Start numbers default to the remembered sequence (first use: B2C 2217, B2B 5271);
 * --record stores the range used so the next month continues from it.
 */
class GstAuditorExport extends Command
{
    protected $signature = 'gst:auditor-export
        {--month= : Calendar month YYYY-MM (alternative to --from/--to)}
        {--from= : First day (Y-m-d)}
        {--to= : Last day (Y-m-d)}
        {--source=new : new | legacy (Lord DB)}
        {--branch= : Restrict to one branch id (default: every branch under the filing GSTIN)}
        {--gstin= : Filing GSTIN (default: HQ branch GSTIN)}
        {--b2c-start= : Running INV NO for the first B2C row}
        {--b2b-start= : Running INV NO for the first B2B row}
        {--out= : Folder for the workbooks (default: storage/app/gst-auditor)}
        {--record : Remember the number ranges used so the next month continues}';

    protected $description = 'Write the auditor GSTR B2C / B2B registers (+ GSTR-1 workbook) for a period';

    public function handle(): int
    {
        if ($m = $this->option('month')) {
            $d = Carbon::createFromFormat('Y-m', $m)->startOfMonth();
            [$from, $to] = [$d->toDateString(), $d->copy()->endOfMonth()->toDateString()];
        } else {
            [$from, $to] = [$this->option('from'), $this->option('to')];
        }
        if (! $from || ! $to) {
            $this->error('Give --month=YYYY-MM or both --from and --to.');

            return self::INVALID;
        }
        $source = $this->option('source') === GstAuditorReport::SOURCE_LEGACY ? GstAuditorReport::SOURCE_LEGACY : GstAuditorReport::SOURCE_NEW;
        if ($source === GstAuditorReport::SOURCE_LEGACY && ! GstAuditorReport::legacyAvailable()) {
            $this->error('The legacy (Lord DB) connection is not reachable.');

            return self::FAILURE;
        }
        $report = new GstAuditorReport($source, $from, $to, $this->option('branch') ? (int) $this->option('branch') : null, $this->option('gstin'));
        $this->info(sprintf('%s → %s · source %s · filing GSTIN %s', $from, $to, $source, $report->gstin));

        $out = $this->option('out') ?: storage_path('app/gst-auditor');
        if (! is_dir($out) && ! mkdir($out, 0775, true)) {
            $this->error("Cannot create $out");

            return self::FAILURE;
        }
        $label = Carbon::parse($from)->format('M Y');
        $files = [];
        $numbered = [];
        foreach (['b2c' => $report->b2cRows(), 'b2b' => $report->b2bRows()] as $register => $rows) {
            $start = (int) ($this->option($register.'-start') ?: GstAuditorReport::nextNumber($register, $from, $to));
            $rows = GstAuditorReport::number($rows, $start);
            $numbered[$register] = $rows;
            $path = $out.DIRECTORY_SEPARATOR.'GSTR REPORT '.strtoupper($register).' '.$label.'.xlsx';
            GstAuditorWorkbook::register($register, $rows, $path);
            $files[] = $path;
            $t = GstAuditorReport::totals($rows);
            $this->table([strtoupper($register), 'rows', 'INV NO range', 'billing value', 'taxable', 'GST 3%'], [[
                $label, $t['rows'], $t['rows'] ? $start.' – '.($start + $t['rows'] - 1) : '—',
                number_format($t['billing'], 2), number_format($t['taxable'], 2), number_format($t['gst'], 2),
            ]]);
            if ($this->option('record')) {
                GstAuditorReport::recordRun($register, $from, $to, $start, $t['rows']);
            }
        }
        $gstr1 = $out.DIRECTORY_SEPARATOR.'GSTR1 '.$label.'.xlsx';
        GstAuditorWorkbook::gstr1($numbered['b2c'], $numbered['b2b'], $gstr1);
        $files[] = $gstr1;

        foreach ($report->notes as $n) {
            $this->warn($n);
        }
        foreach ($files as $f) {
            $this->line('  → '.$f);
        }
        $this->info($this->option('record') ? 'Number ranges recorded.' : 'Number ranges NOT recorded (pass --record once the files are final).');

        return self::SUCCESS;
    }
}
