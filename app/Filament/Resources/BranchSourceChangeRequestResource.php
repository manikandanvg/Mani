<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\BranchSourceChangeRequestResource\Pages;
use App\Models\Branch;
use App\Models\BranchSourceChangeRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * "Order Source" setting. A distributor requests (one-time) to change the branch they order
 * stock from — choosing only from branches ABOVE them in the chain (plus Head Office). Head
 * Office approves, which re-points the branch's source_branch_id, and the Order Form then
 * orders from the new supplier. Branch-scoped: a distributor sees only their own requests.
 */
class BranchSourceChangeRequestResource extends BaseResource
{
    use BranchScoped;

    protected static ?string $model = BranchSourceChangeRequest::class;

    protected static ?string $navigationLabel = 'Order Source Request';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'System';

    public static function getNavigationBadge(): ?string
    {
        // scoped by BranchScoped: HQ sees all pending, a distributor sees only their own
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    /** Only Head Office (non-distributor staff) may approve/reject. */
    protected static function isApprover(): bool
    {
        $u = auth()->user();

        return $u && (! method_exists($u, 'isDistributor') || ! $u->isDistributor());
    }

    public static function form(Form $form): Form
    {
        $isDistributor = (bool) auth()->user()?->isDistributor();

        return $form->schema([
            Forms\Components\Select::make('branch_id')
                ->label('Branch')
                ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                ->default(fn () => auth()->user()?->branch_id)
                ->disabled($isDistributor)       // a distributor can only request for their own branch
                ->dehydrated()                   // …but still submit the value
                ->required()
                ->searchable()
                ->live(),
            Forms\Components\Select::make('requested_source_branch_id')
                ->label('New supplier (order source)')
                ->helperText('Only branches above you in the chain — plus Head Office — can be your supplier.')
                ->options(function (Get $get) {
                    $branch = Branch::find($get('branch_id') ?? auth()->user()?->branch_id);

                    return $branch ? $branch->sourceCandidates()->pluck('name', 'id')->all() : [];
                })
                ->searchable()->required()
                ->different('branch_id'),
            Forms\Components\Textarea::make('note')->label('Reason (optional)')->maxLength(255)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable(),
                Tables\Columns\TextColumn::make('currentSource.name')->label('Current source')->placeholder('—'),
                Tables\Columns\TextColumn::make('requestedSource.name')->label('Requested source'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('requester.name')->label('By')->placeholder('—'),
                Tables\Columns\TextColumn::make('decided_at')->dateTime()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (BranchSourceChangeRequest $r) => $r->status === 'pending' && static::isApprover())
                    ->action(fn (BranchSourceChangeRequest $r) => static::decide($r, 'approved')),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BranchSourceChangeRequest $r) => $r->status === 'pending' && static::isApprover())
                    ->action(fn (BranchSourceChangeRequest $r) => static::decide($r, 'rejected')),
            ]);
    }

    /** Approve (re-point the branch source) or reject, stamping who/when. */
    protected static function decide(BranchSourceChangeRequest $request, string $status): void
    {
        DB::transaction(function () use ($request, $status) {
            if ($status === 'approved') {
                $request->branch->update(['source_branch_id' => $request->requested_source_branch_id]);
            }
            $request->update([
                'status' => $status,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
            ]);
        });

        // Decision acknowledgement to the requesting dealer (board 2026-08-11).
        \App\Services\Push\Notifier::to(
            $request->branch?->distributorUser?->memberAccount,
            'order',
            'Supplier change ' . $status,
            $status === 'approved'
                ? 'Your stock-source change was approved — future orders route to the new supplier.'
                : 'Your stock-source change request was rejected. Your current supplier stays unchanged.',
            route: '/stock-orders',
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranchSourceChangeRequests::route('/'),
            'create' => Pages\CreateBranchSourceChangeRequest::route('/create'),
        ];
    }
}
