<?php

namespace App\Console\Commands;

use App\Services\Geo\BranchPincodeFiller;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Board 2026-10-31 (common 1): branches without a pincode — list them, export the Excel
 * file, and auto-fill what the address / city resolves against the pincode master.
 *
 *   php artisan branches:pincodes                     # list the branches still missing a PIN
 *   php artisan branches:pincodes --fill              # fill what can be resolved, then list the rest
 *   php artisan branches:pincodes --export=branches-without-pincode.xlsx
 */
class BranchPincodes extends Command
{
    protected $signature = 'branches:pincodes
        {--fill : Write the resolved pincodes onto the branches}
        {--export= : Write the (remaining) branches without a pincode to this .xlsx file}';

    protected $description = 'List / export / auto-fill branches without a pincode (network hierarchy key)';

    public function handle(BranchPincodeFiller $filler): int
    {
        $result = $filler->fillAll(dryRun: ! $this->option('fill'));

        $this->table(['#', 'Branch', 'City', 'Address', $this->option('fill') ? 'Pincode set' : 'Would set', 'How'],
            collect($result['rows'])->map(fn ($r) => [
                $r['branch']->id, $r['branch']->name, $r['branch']->city,
                \Illuminate\Support\Str::limit((string) $r['branch']->address, 50),
                $r['pincode'] ?? '—', $r['how'],
            ])->all());
        $this->info(($this->option('fill') ? 'Filled' : 'Resolvable') . ": {$result['filled']} · still missing: {$result['missing']}");

        if ($out = $this->option('export')) {
            $rows = collect($result['rows'])->filter(fn ($r) => ! $r['pincode'] || ! $this->option('fill'));
            $writer = new XlsxWriter;
            $writer->openToFile($out);
            $writer->addRow(Row::fromValues(['Branch ID', 'Branch', 'In-charge', 'Phone', 'Address', 'City', 'Country', 'Suggested pincode', 'Note']));
            foreach ($rows as $r) {
                $b = $r['branch'];
                $writer->addRow(Row::fromValues([$b->id, $b->name, $b->incharge, $b->phone, $b->address, $b->city, $b->country, $r['pincode'] ?? '', $r['how']]));
            }
            $writer->close();
            $this->info("Exported {$rows->count()} branch(es) → {$out}");
        }

        return self::SUCCESS;
    }
}
