<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\BranchStockDay;
use App\Services\Tasks\TaskEngine;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Monthly Tasks → Stock Tracking (board 2026-08-29): day-by-day bars of a branch's
 * stock against the Opening level for one month (29/30/31 days). Red = a shortfall
 * day; grey = no snapshot (branch closed / before the feature). Same data the app shows.
 */
class StockTracking extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';

    protected static ?string $navigationGroup = 'Monthly Tasks';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Stock Tracking';

    protected static ?string $title = 'Stock Tracking';

    protected static string $view = 'filament.pages.stock-tracking';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && ! ($u->isSupport() ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $u = auth()->user();
        $this->form->fill([
            'branch_id' => $u?->isDistributor() ? $u->branch_id : ($u?->branch_id ?? Branch::where('is_active', true)->where('level', '!=', 'hq')->value('id')),
            'month' => Carbon::now()->format('Y-m'),
            'product_id' => null,
        ]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->statePath('data')->columns(3)->schema([
            Forms\Components\Select::make('branch_id')->label('Branch')
                ->options(fn () => Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->disabled(fn () => (bool) auth()->user()?->isDistributor())->dehydrated()
                ->searchable()->live(),
            Forms\Components\Select::make('month')->options(\App\Filament\Resources\TaskAssignmentResource::monthOptions())->live()->native(false),
            Forms\Components\Select::make('product_id')->label('Item')
                ->placeholder('Worst item each day')
                ->options(fn (Forms\Get $get) => BranchStockDay::with('catalogProduct')->where('branch_id', $get('branch_id'))
                    ->get()->unique('catalog_product_id')
                    ->mapWithKeys(fn ($r) => [$r->catalog_product_id => Translatable::pick($r->catalogProduct?->name) ?: ($r->catalogProduct?->code ?? '#' . $r->catalog_product_id)])->all())
                ->live(),
        ]);
    }

    public function chart(): ?array
    {
        $branchId = $this->data['branch_id'] ?? null;
        if (! $branchId || ! ($branch = Branch::find($branchId))) {
            return null;
        }
        $month = ($this->data['month'] ?? null) ? Carbon::createFromFormat('Y-m', $this->data['month']) : Carbon::now();

        return app(TaskEngine::class)->stockChart($branch, $month, $this->data['product_id'] ?? null);
    }
}
