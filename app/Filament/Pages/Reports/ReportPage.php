<?php

namespace App\Filament\Pages\Reports;

use App\Models\Branch;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use League\Csv\Writer;

/**
 * Base of the Report group (board spec 2026-08-09): filter form → run() fills
 * $sections (rendered by the shared data-sections partial) → optional CSV download
 * of exactly what is on screen. Admin sees all branches; a dealer login is pinned
 * to their own branch. Hidden from the support persona entirely.
 */
abstract class ReportPage extends Page implements HasForms
{
    use \App\Filament\Concerns\ExportsSections;
    use \App\Filament\Concerns\TranslatesNavigation;
    use \App\Filament\Concerns\HiddenFromSupport;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.report-page';

    protected static ?string $navigationGroup = 'Report';

    public ?array $data = [];

    /** @var array<int, array{heading?:string, kv?:array, columns?:array, rows?:array}> */
    public array $sections = [];

    public bool $ran = false;

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    protected function getFormActions(): array
    {
        return [];
    }

    abstract public function run(): void;

    protected static function dealerBranchId(): ?int
    {
        $u = auth()->user();

        return ($u && method_exists($u, 'isDistributor') && $u->isDistributor() && $u->branch_id)
            ? (int) $u->branch_id
            : null;
    }

    /** Branch select options — a dealer only ever sees their own branch. */
    protected static function branchOptions(): array
    {
        $q = Branch::orderBy('name');
        if ($b = static::dealerBranchId()) {
            $q->where('id', $b);
        }

        return $q->pluck('name', 'id')->all();
    }

    /** The branch id to filter by: the dealer's own, else the form's pick (null = all). */
    protected function branchFilter(): ?int
    {
        return static::dealerBranchId() ?? (($v = $this->form->getState()['branch_id'] ?? null) ? (int) $v : null);
    }

    protected function money($value): string
    {
        return \App\Support\Money::group((float) $value);
    }

    protected function dmy($date): string
    {
        return $date ? \Illuminate\Support\Carbon::parse($date)->format('d M Y') : '—';
    }

    // CSV / Excel / PDF / Print come from the shared ExportsSections trait.
}
