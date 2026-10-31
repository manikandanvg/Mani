<?php

namespace App\Services\Stock;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Support\Translatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Board 2026-10-31 (common 2): branches hand over their OPENING stock as an Excel file;
 * this sets each branch's stock to the sheet (quantity in pieces, and the "Opening" level
 * used by the low-stock alert) and writes an 'opening' adjustment movement for the audit.
 *
 * Two layouts are accepted (header row required, names are case-insensitive):
 *
 *   LONG  — one row per branch + product:
 *           Branch | Product | Qty [| Opening] [| Purity] [| Rate]
 *   WIDE  — one row per branch, one column per product (the template this class writes):
 *           Branch | 8 g Gold | 4 g Gold | … | 1000 g Silver
 *
 * "Branch" is the branch id, its exact name, or "id - name". "Product" is the catalog code or
 * name. Blank cells are skipped; a cell of 0 sets the stock to zero.
 */
class OpeningStockImporter
{
    /** @var array<int, array{row:int, message:string}> */
    public array $errors = [];

    public int $applied = 0;

    public int $skipped = 0;

    /** @var array<int, array{branch:string, product:string, from:float, to:float}> */
    public array $changes = [];

    public function import(string $path, bool $dryRun = false, ?int $onlyBranchId = null, ?int $userId = null): self
    {
        $this->errors = [];
        $this->changes = [];
        $this->applied = $this->skipped = 0;

        $rows = $this->rows($path);
        if ($rows->isEmpty()) {
            $this->errors[] = ['row' => 0, 'message' => 'The file has no data rows.'];

            return $this;
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $rows->first());
        $data = $rows->slice(1)->values();
        $products = $this->products();
        $branches = $this->branches();

        $qtyCol = $this->col($headers, ['qty', 'quantity', 'pieces', 'pcs', 'stock']);
        $productCol = $this->col($headers, ['product', 'item', 'catalog', 'code']);
        $branchCol = $this->col($headers, ['branch', 'branch name', 'dealer', 'branch id']) ?? 0;

        $entries = [];   // [rowNo, branchKey, productKey, qty, opening, purity, rate]
        if ($qtyCol !== null && $productCol !== null) {
            $openingCol = $this->col($headers, ['opening', 'min', 'minimum', 'opening level']);
            $purityCol = $this->col($headers, ['purity']);
            $rateCol = $this->col($headers, ['rate', 'cost', 'purchase rate']);
            foreach ($data as $i => $r) {
                $entries[] = [$i + 2, $r[$branchCol] ?? null, $r[$productCol] ?? null, $r[$qtyCol] ?? null,
                    $openingCol !== null ? ($r[$openingCol] ?? null) : null,
                    $purityCol !== null ? ($r[$purityCol] ?? null) : null,
                    $rateCol !== null ? ($r[$rateCol] ?? null) : null];
            }
        } else {
            // wide: every non-branch column is a product
            foreach ($headers as $c => $h) {
                if ($c === $branchCol || $h === '' || in_array($h, ['branch id', 'id', 'city', 'name'], true)) {
                    continue;
                }
                foreach ($data as $i => $r) {
                    $entries[] = [$i + 2, $r[$branchCol] ?? null, $rows->first()[$c], $r[$c] ?? null, null, null, null];
                }
            }
        }

        DB::transaction(function () use ($entries, $products, $branches, $dryRun, $onlyBranchId, $userId) {
            foreach ($entries as [$rowNo, $branchKey, $productKey, $qty, $opening, $purity, $rate]) {
                if ($qty === null || trim((string) $qty) === '') {
                    $this->skipped++;
                    continue;
                }
                $branch = $this->matchBranch($branches, $branchKey);
                if (! $branch) {
                    $this->errors[] = ['row' => $rowNo, 'message' => 'Unknown branch "' . $branchKey . '"'];
                    continue;
                }
                if ($onlyBranchId && (int) $branch->id !== $onlyBranchId) {
                    $this->errors[] = ['row' => $rowNo, 'message' => 'Row belongs to another branch (' . $branch->name . ')'];
                    continue;
                }
                $product = $this->matchProduct($products, $productKey);
                if (! $product) {
                    $this->errors[] = ['row' => $rowNo, 'message' => 'Unknown product "' . $productKey . '"'];
                    continue;
                }
                if (! is_numeric(str_replace(',', '', (string) $qty))) {
                    $this->errors[] = ['row' => $rowNo, 'message' => 'Quantity "' . $qty . '" is not a number'];
                    continue;
                }
                $to = (float) str_replace(',', '', (string) $qty);
                $stock = Stock::firstOrNew(['branch_id' => $branch->id, 'catalog_product_id' => $product->id, 'order_line_id' => 0]);
                $from = (float) ($stock->exists ? $stock->quantity : 0);
                $this->changes[] = ['branch' => $branch->name, 'product' => $product->code, 'from' => $from, 'to' => $to];
                $this->applied++;
                if ($dryRun) {
                    continue;
                }
                $stock->quantity = $to;
                $stock->min_qty = $opening !== null && $opening !== '' ? (float) $opening : $to;
                if ($purity !== null && $purity !== '') {
                    $stock->purity = (string) $purity;
                }
                if ($rate !== null && $rate !== '' && is_numeric($rate)) {
                    $stock->last_rate = (float) $rate;
                }
                $stock->save();
                if (abs($to - $from) > 0.00005) {
                    StockMovement::create([
                        'branch_id' => $branch->id,
                        'catalog_product_id' => $product->id,
                        'type' => 'adjustment',
                        'qty_change' => round($to - $from, 4),
                        'balance_after' => $to,
                        'ref_type' => 'opening',
                        'note' => 'Opening stock import',
                        'moved_on' => Carbon::now()->toDateString(),
                        'created_by' => $userId,
                    ]);
                }
            }
        });

        return $this;
    }

    /** Wide-format template: one row per active branch, one column per saleable product. */
    public function template(string $path, ?int $onlyBranchId = null): string
    {
        $products = $this->products()->reject(fn ($p) => $p->material === 'cash' || $p->is_custom_order)->values();
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_merge(['Branch'], $products->map(fn ($p) => $p->code . ' — ' . Translatable::pick($p->name))->all())));
        $branches = Branch::where('is_active', true)->when($onlyBranchId, fn ($q) => $q->whereKey($onlyBranchId))->orderBy('name')->get();
        $current = Stock::where('order_line_id', 0)->get()->keyBy(fn ($s) => $s->branch_id . ':' . $s->catalog_product_id);
        foreach ($branches as $b) {
            $cells = [$b->id . ' - ' . $b->name];
            foreach ($products as $p) {
                $s = $current->get($b->id . ':' . $p->id);
                $cells[] = $s ? (float) $s->quantity : '';
            }
            $writer->addRow(Row::fromValues($cells));
        }
        $writer->close();

        return $path;
    }

    // ------------------------------------------------------------------ helpers

    protected function rows(string $path): Collection
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $reader = $ext === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : $c, $row->toArray());
                if (count(array_filter($cells, fn ($c) => $c !== null && trim((string) $c) !== '')) === 0) {
                    continue;
                }
                $out[] = $cells;
            }
            break;   // first sheet only
        }
        $reader->close();

        return collect($out);
    }

    protected function col(array $headers, array $names): ?int
    {
        foreach ($headers as $i => $h) {
            if (in_array($h, $names, true)) {
                return $i;
            }
        }

        return null;
    }

    protected function products(): Collection
    {
        return CatalogProduct::where('is_active', true)->get();
    }

    protected function branches(): Collection
    {
        return Branch::all();
    }

    protected function matchBranch(Collection $branches, $key): ?Branch
    {
        $k = trim((string) $key);
        if ($k === '') {
            return null;
        }
        if (preg_match('/^(\d+)\s*[-–:]/', $k, $m) || ctype_digit($k)) {
            $id = (int) ($m[1] ?? $k);
            if ($b = $branches->firstWhere('id', $id)) {
                return $b;
            }
        }
        $lower = mb_strtolower($k);
        $exact = $branches->filter(fn ($b) => mb_strtolower(trim((string) $b->name)) === $lower);
        if ($exact->count() === 1) {
            return $exact->first();
        }

        return null;
    }

    protected function matchProduct(Collection $products, $key): ?CatalogProduct
    {
        $k = mb_strtolower(trim((string) $key));
        if ($k === '') {
            return null;
        }
        // "G8 — 8 g Gold" template header → code before the dash
        $code = mb_strtolower(trim(preg_split('/\s[—–-]\s/', $k)[0] ?? $k));
        foreach ($products as $p) {
            if (mb_strtolower((string) $p->code) === $code || mb_strtolower((string) $p->code) === $k) {
                return $p;
            }
        }
        foreach ($products as $p) {
            $name = mb_strtolower((string) Translatable::pick($p->name));
            if ($name === $k || $name === $code || ($name !== '' && str_ends_with($k, $name))) {
                return $p;
            }
        }

        return null;
    }
}
