<?php

namespace App\Filament\Pages\Track;

use App\Models\Branch;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;

/**
 * Track Stock — a branch's current holdings and its recent movement ledger
 * (purchases in, sales out, transfers, returns). Dealers are pinned to their branch.
 */
class TrackStock extends TrackPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Track Stock';

    protected static ?string $title = 'Track Stock';

    public function form(Form $form): Form
    {
        $dealerBranch = static::dealerBranchId();

        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\Select::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::orderBy('name')->pluck('name', 'id'))
                    ->default($dealerBranch ?? 1)
                    ->disabled($dealerBranch !== null)
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('product')
                    ->label('Product filter (optional)')
                    ->placeholder(__('e.g. coin, chain')),
            ]),
        ])->statePath('data');
    }

    public function lookup(): void
    {
        $d = $this->form->getState();
        // disabled inputs don't submit — a dealer's branch always comes from the login
        $branchId = static::dealerBranchId() ?? (int) ($d['branch_id'] ?? 0);
        $filter = trim((string) ($d['product'] ?? ''));
        $this->sections = [];
        $this->searched = true;

        if (! $branchId) {
            return;
        }

        $name = fn ($cp) => $cp ? Translatable::pick($cp->name) : '—';

        $stock = Stock::with('catalogProduct')
            ->where('branch_id', $branchId)
            ->get()
            ->when($filter !== '', fn ($c) => $c->filter(
                fn ($s) => stripos((string) $name($s->catalogProduct), $filter) !== false
            ));
        // stock.quantity = pieces; show the gram equivalent beside it
        $this->sections[] = ['heading' => __('Current stock'),
            'columns' => [__('Product'), __('Purity'), __('Pieces / ₹'), __('Weight (g)'), __('Last rate')],
            'rows' => $stock->map(fn ($s) => [
                $name($s->catalogProduct), $s->purity, number_format((float) $s->quantity, 4),
                $s->catalogProduct && $s->catalogProduct->material !== 'cash'
                    ? number_format($s->catalogProduct->gramsFromPieces((float) $s->quantity), 3)
                    : '—',
                $this->money($s->last_rate),
            ])->values()->all()];

        $moves = StockMovement::with('catalogProduct')
            ->where('branch_id', $branchId)
            ->latest('moved_on')->latest('id')->limit(40)->get()
            ->when($filter !== '', fn ($c) => $c->filter(
                fn ($m) => stripos((string) $name($m->catalogProduct), $filter) !== false
            ));
        $this->sections[] = ['heading' => __('Recent movements'),
            'columns' => [__('Date'), __('Product'), __('Type'), __('Pieces change'), __('Balance after'), __('Reference')],
            'rows' => $moves->map(fn ($m) => [
                $this->dmy($m->moved_on), $name($m->catalogProduct), ucfirst((string) $m->type),
                number_format((float) $m->qty_change, 4), number_format((float) $m->balance_after, 4),
                trim(($m->ref_type ?? '') . ' #' . ($m->ref_id ?? ''), ' #'),
            ])->values()->all()];
    }
}
