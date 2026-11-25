<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Board 2026-11-25: every audit CSV starts with WHO the branch is — Branch, Branch Incharge,
 * Staff phone, City — and then the audit's own columns.
 *
 * bLabel() gives the one-cell name the console tables print ("NAME / City [#id]"); writeCsv()
 * finds the "Branch" column of a list and replaces it by those four columns at the front.
 */
trait BranchCsv
{
    /** @var array<int, object> */
    protected array $branchRows = [];

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string}> label → [branch, in-charge, staff phone, city] */
    protected array $branchInfo = [];

    protected function loadBranches(): void
    {
        $this->branchRows = DB::table('branches')->get(['id', 'name', 'city', 'level', 'incharge', 'phone'])->keyBy('id')->all();
        // the staff phone = the phone of the branch's own login (first active one), else the branch phone
        $staff = [];
        foreach (DB::table('users')->whereNotNull('branch_id')->whereNotNull('phone')->where('phone', '!=', '')->whereNull('deleted_at')
            ->orderByRaw("status = 'active' desc")->orderBy('id')->get(['branch_id', 'phone']) as $u) {
            $staff[$u->branch_id] ??= $u->phone;
        }
        foreach ($this->branchRows as $id => $b) {
            $this->branchInfo[$this->bLabel($id)] = [trim((string) $b->name) . ' [#' . $id . ']', trim((string) $b->incharge),
                (string) ($staff[$id] ?? $b->phone ?? ''), trim((string) $b->city)];
        }
    }

    protected function bLabel($id): string
    {
        $b = $this->branchRows[$id] ?? null;

        return $b ? trim($b->name . ($b->city ? ' / ' . $b->city : '')) . ' [#' . $id . ']' : 'Branch #' . $id;
    }

    /** Write a list; a "Branch" column becomes Branch, Branch Incharge, Staff phone, City at the front. */
    protected function writeCsv(string $path, array $head, array $rows): void
    {
        if (! $rows) {
            return;
        }
        $at = array_search('Branch', $head, true);
        if ($at !== false) {
            unset($head[$at]);
            $head = array_merge(['Branch', 'Branch Incharge', 'Staff phone', 'City'], array_values($head));
        }
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");   // Excel reads UTF-8
        fputcsv($fh, $head);
        foreach ($rows as $row) {
            $row = array_values($row);
            if ($at !== false) {
                $label = (string) ($row[$at] ?? '');
                unset($row[$at]);
                $row = array_merge($this->branchInfo[$label] ?? [$label, '', '', ''], array_values($row));
            }
            fputcsv($fh, $row);
        }
        fclose($fh);
        $this->line('Written: ' . $path . ' (' . count($rows) . ' rows)');
    }
}
