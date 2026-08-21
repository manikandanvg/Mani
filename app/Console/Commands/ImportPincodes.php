<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-seed the pincode master from the "All India Pincode Directory" CSV
 * (data.gov.in, ~157k post-office rows). Column names are matched loosely so
 * the periodically re-published dataset keeps importing without code changes.
 *
 *   php artisan pincodes:import storage/app/pincodes/all_india.csv
 */
class ImportPincodes extends Command
{
    protected $signature = 'pincodes:import {file : Path to the pincode directory CSV}';

    protected $description = 'Import the All-India Pincode Directory CSV into the pincodes master';

    public function handle(): int
    {
        // 165k-row upserts overrun the default 128M CLI limit (dies silently at ~88k)
        ini_set('memory_limit', '1024M');

        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $fh = fopen($file, 'r');
        $header = fgetcsv($fh);
        if (! $header) {
            $this->error('Could not read a header row.');

            return self::FAILURE;
        }
        // strip BOM + normalize: "Office Name" / officename / OFFICENAME all match
        $norm = array_map(fn ($h) => strtolower(preg_replace('/[^a-z]/i', '', preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);

        $col = function (array $candidates) use ($norm): ?int {
            foreach ($candidates as $c) {
                $i = array_search($c, $norm, true);
                if ($i !== false) {
                    return $i;
                }
            }

            return null;
        };

        $iPin = $col(['pincode', 'pin']);
        $iOffice = $col(['officename', 'office']);
        $iTaluk = $col(['taluk', 'taluka', 'block', 'subdistname']);
        $iDistrict = $col(['districtname', 'district']);
        $iState = $col(['statename', 'state']);

        if ($iPin === null || $iOffice === null) {
            $this->error('Header must contain at least pincode + office name columns. Found: ' . implode(', ', $header));

            return self::FAILURE;
        }

        $batch = [];
        $count = 0;
        $now = now();
        $flush = function () use (&$batch, &$count) {
            if (! $batch) {
                return;
            }
            // last write wins on (pincode, office) so re-imports refresh the master
            DB::table('pincodes')->upsert($batch, ['pincode', 'office'], ['taluka', 'district', 'state', 'source', 'updated_at']);
            $count += count($batch);
            $batch = [];
        };

        while (($row = fgetcsv($fh)) !== false) {
            $pin = trim((string) ($row[$iPin] ?? ''));
            $office = trim((string) ($row[$iOffice] ?? ''));
            if (! preg_match('/^[1-9][0-9]{5}$/', $pin) || $office === '') {
                continue;
            }
            $batch[] = [
                'pincode' => $pin,
                'office' => mb_substr($office, 0, 150),
                'taluka' => $iTaluk !== null ? (trim((string) ($row[$iTaluk] ?? '')) ?: null) : null,
                'district' => $iDistrict !== null ? (trim((string) ($row[$iDistrict] ?? '')) ?: null) : null,
                'state' => $iState !== null ? (trim((string) ($row[$iState] ?? '')) ?: null) : null,
                'source' => 'import',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) >= 1000) {
                $flush();
                $this->output->write("\r  imported {$count} rows…");
            }
        }
        $flush();
        fclose($fh);

        $this->newLine();
        $this->info("Done — {$count} post-office rows in the pincode master.");

        return self::SUCCESS;
    }
}
