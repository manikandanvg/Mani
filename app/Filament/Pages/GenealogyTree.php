<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Support\GenealogyRenderer;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;

/**
 * Network → Distributor Place (folder tree, default) / org chart. A hand-built org chart — no third-party charting library.
 * Pure CSS connectors + Alpine for collapse/expand, live search and drag-to-pan/zoom.
 * Head-office only. The bespoke alternative to the BALKAN-powered Genealogy page.
 */
class GenealogyTree extends Page
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use HqOnly;

    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationGroup = 'Network';

    protected static ?string $navigationLabel = 'Distributor Place';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Distributor Place';

    protected static ?string $slug = 'genealogy';

    protected static string $view = 'filament.pages.genealogy-tree';

    /**
     * Board 2026-10-24: the board does not want an MLM-style org chart as the default — the
     * network is shown as a FOLDER tree (system file-explorer style: each distributor is a
     * folder holding the distributors placed under them). The chart remains one click away.
     *   /admin/genealogy            → folder view (default)
     *   /admin/genealogy?view=chart → org chart
     */
    public string $mode = 'folder';

    public function mount(): void
    {
        // 16k+ members: the tree is built in one pass; give the request headroom on 128 MB hosts.
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 512) {
            ini_set('memory_limit', '512M');
        }
        $this->mode = request()->query('view') === 'chart' ? 'chart' : 'folder';
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;   // use the full screen width for the chart
    }

    /**
     * Nested member tree. Roots = no upline (or an upline that no longer exists).
     * Built from plain rows in one pass (GenealogyRenderer) — 9k Eloquent models plus a
     * recursive Blade partial exhausted 128 MB on this page (2026-10-24).
     */
    public function getTree(): array
    {
        return GenealogyRenderer::tree();
    }
}
