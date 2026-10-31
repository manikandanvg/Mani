<?php

namespace App\Console\Commands;

use App\Services\Stock\OpeningStockImporter;
use Illuminate\Console\Command;

/**
 * Board 2026-10-31 (common 2): load the branches' opening stock from their Excel file.
 *
 *   php artisan stock:import-opening --template=opening-stock.xlsx   # sheet to hand out (current stock pre-filled)
 *   php artisan stock:import-opening opening-stock.xlsx --dry-run    # check branches / products / numbers
 *   php artisan stock:import-opening opening-stock.xlsx              # apply
 */
class StockImportOpening extends Command
{
    protected $signature = 'stock:import-opening
        {file? : .xlsx or .csv with the opening stock (long or wide layout)}
        {--dry-run : Validate and show the changes without writing}
        {--branch= : Only accept rows of this branch id}
        {--template= : Instead of importing, write the wide-format template to this .xlsx path}';

    protected $description = 'Import branch opening stock from Excel (or write the template)';

    public function handle(OpeningStockImporter $importer): int
    {
        if ($out = $this->option('template')) {
            $importer->template($out, $this->option('branch') ? (int) $this->option('branch') : null);
            $this->info("Template written → {$out}");

            return self::SUCCESS;
        }

        $file = $this->argument('file');
        if (! $file || ! is_file($file)) {
            $this->error('Give the .xlsx/.csv file to import, or --template=path to get the sheet.');

            return self::FAILURE;
        }

        $importer->import($file, (bool) $this->option('dry-run'), $this->option('branch') ? (int) $this->option('branch') : null);

        if ($importer->changes) {
            $this->table(['Branch', 'Product', 'From', 'To'], array_map(fn ($c) => [$c['branch'], $c['product'], $c['from'], $c['to']], $importer->changes));
        }
        foreach ($importer->errors as $e) {
            $this->warn("Row {$e['row']}: {$e['message']}");
        }
        $this->info(($this->option('dry-run') ? 'Would apply' : 'Applied') . ": {$importer->applied} · skipped blanks: {$importer->skipped} · errors: " . count($importer->errors));

        return $importer->errors ? self::FAILURE : self::SUCCESS;
    }
}
