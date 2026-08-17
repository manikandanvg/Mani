<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\Member;
use App\Support\Translatable;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;

/**
 * Network → Genealogy (Tree). A hand-built org chart — no third-party charting library.
 * Pure CSS connectors + Alpine for collapse/expand, live search and drag-to-pan/zoom.
 * Head-office only. The bespoke alternative to the BALKAN-powered Genealogy page.
 */
class GenealogyTree extends Page
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use HqOnly;

    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationGroup = 'Network';

    protected static ?string $navigationLabel = 'Genealogy';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Distributor Genealogy';

    protected static ?string $slug = 'genealogy';

    protected static string $view = 'filament.pages.genealogy-tree';

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;   // use the full screen width for the chart
    }

    /** Nested member tree. Roots = no upline (or an upline that no longer exists). */
    public function getTree(): array
    {
        $locale = Translatable::defaultLocale();

        $members = Member::query()
            ->select('id', 'member_code', 'name', 'upline_id', 'rank_id', 'status', 'pan_verified', 'aadhaar_verified')
            ->with('rank:id,name')
            ->orderBy('id')
            ->get();

        $byParent = $members->groupBy('upline_id');
        $ids = $members->keyBy('id');

        $build = function (Member $m) use (&$build, $byParent, $locale) {
            $children = ($byParent->get($m->id) ?? collect())->map($build)->all();
            $descendants = count($children) + array_sum(array_column($children, 'descendants'));

            return [
                'id' => $m->id,
                'name' => $m->name,
                'code' => $m->member_code,
                'position' => $m->rank ? Translatable::pick($m->rank->name, $locale) : '',
                'active' => $m->status === 'active',
                'verified' => $m->kyc_verified,   // green KYC badge (board item 1/2)
                'initial' => strtoupper(mb_substr(trim($m->name) !== '' ? trim($m->name) : '?', 0, 1)),
                'url' => url('/admin/members/' . $m->id . '/edit'),
                'children' => $children,
                'descendants' => $descendants,
            ];
        };

        return $members
            ->filter(fn (Member $m) => empty($m->upline_id) || ! $ids->has($m->upline_id))
            ->map($build)
            ->values()
            ->all();
    }
}
