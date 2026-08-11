<?php

namespace App\Filament\Pages\Track;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

/**
 * Base of the five Track screens (board spec 2026-08-09): search-driven lookup pages
 * that pull a subject's full story together for the support desk and dealers.
 * Visible to ALL three personas — admins and support staff see them under
 * "Support & Track"; a dealer sees the same screens under a plain "Track" group,
 * with every query silently limited to their own branch.
 *
 * Subclasses implement lookup() and fill $sections with
 * ['heading' => …, 'kv' => [label => value]] and/or ['columns' => […], 'rows' => [[…]]].
 */
abstract class TrackPage extends Page implements HasForms
{
    use \App\Filament\Concerns\ExportsSections;
    use \App\Filament\Concerns\TranslatesNavigation;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.track-page';

    public ?array $data = [];

    /** @var array<int, array{heading?:string, kv?:array, columns?:array, rows?:array}> */
    public array $sections = [];

    public bool $searched = false;

    public static function getNavigationGroup(): ?string
    {
        // Board 2026-08-11: ONE group name for every persona — dealers see the same
        // "Support & Track" group (tickets + track screens) as HQ and support staff.
        return __('Support & Track');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getFormActions(): array
    {
        return [];
    }

    abstract public function lookup(): void;

    /** Dealer logins are pinned to their own branch; HQ/support pass null (= all). */
    protected static function dealerBranchId(): ?int
    {
        $u = auth()->user();

        return ($u && method_exists($u, 'isDistributor') && $u->isDistributor() && $u->branch_id)
            ? (int) $u->branch_id
            : null;
    }

    protected function money($value): string
    {
        return number_format((float) $value, 2);
    }

    protected function dmy($date): string
    {
        return $date ? \Illuminate\Support\Carbon::parse($date)->format('d M Y') : '—';
    }
}
