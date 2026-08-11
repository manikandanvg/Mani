<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\SupportDesk;
use App\Filament\Resources\SupportTicketResource\Pages;
use App\Filament\Resources\SupportTicketResource\RelationManagers\RepliesRelationManager;
use App\Models\Member;
use App\Models\SupportTicket;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Navigation\NavigationItem;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Support-desk tickets (board spec 2026-08-09). One table, TWO sidebar entries —
 * "Open Tickets" and "Closed Tickets" — via getNavigationItems(). Visible to
 * head-office and support staff (SupportDesk); dealers use chat/phone instead.
 */
class SupportTicketResource extends BaseResource
{
    protected static ?string $model = SupportTicket::class;

    /**
     * Board 2026-08-11: tickets serve DEALERS too — a distributor raises and follows
     * tickets for their own branch; HQ and support staff see everything. Dealer
     * queries are branch-scoped below; closing stays an HQ/support action.
     */
    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check();
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        $u = auth()->user();

        if ($u && method_exists($u, 'isDistributor') && $u->isDistributor() && $u->branch_id) {
            $query->where(fn ($w) => $w
                ->where('branch_id', $u->branch_id)
                ->orWhere('opened_by', $u->id));
        }

        return $query;
    }

    protected static ?string $navigationGroup = 'Support & Track';

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    /** Two nav entries backed by the same list page, split by the status tab. */
    public static function getNavigationItems(): array
    {
        $visible = fn (): bool => static::shouldRegisterNavigation();

        return [
            NavigationItem::make(__('Open Tickets'))
                ->group(static::getNavigationGroup())
                ->icon('heroicon-o-ticket')
                ->sort(1)
                ->url(static::getUrl('index', ['activeTab' => 'open']))
                ->badge(fn () => ($n = SupportTicket::where('status', 'open')->count()) > 0 ? (string) $n : null)
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteBaseName() . '.*')
                    && request()->query('activeTab', 'open') !== 'closed')
                ->visible($visible),
            NavigationItem::make(__('Closed Tickets'))
                ->group(static::getNavigationGroup())
                ->icon('heroicon-o-check-badge')
                ->sort(2)
                ->url(static::getUrl('index', ['activeTab' => 'closed']))
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteBaseName() . '.*')
                    && request()->query('activeTab') === 'closed')
                ->visible($visible),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('subject')->required()->maxLength(200)->columnSpanFull(),
                Forms\Components\Select::make('category')->options(SupportTicket::CATEGORIES)->native(false),
                Forms\Components\Select::make('priority')->options(SupportTicket::PRIORITIES)
                    ->default('medium')->required()->native(false),
                Forms\Components\Select::make('member_id')->label('Distributor')
                    ->getSearchResultsUsing(fn (string $search) => Member::query()
                        // a dealer raises tickets for their own branch's members only
                        ->when(auth()->user()?->isDistributor() && auth()->user()->branch_id,
                            fn ($q) => $q->where('branch_id', auth()->user()->branch_id))
                        ->where(fn ($w) => $w->where('member_code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"))
                        ->limit(25)->pluck('name', 'id')
                        ->map(fn ($name, $id) => Member::find($id)?->member_code . ' — ' . $name))
                    ->getOptionLabelUsing(fn ($value) => ($m = Member::find($value)) ? "{$m->member_code} — {$m->name}" : null)
                    ->searchable()->preload(false),
                Forms\Components\Select::make('branch_id')->relationship('branch', 'name')->native(false)
                    ->default(fn () => auth()->user()?->isDistributor() ? auth()->user()->branch_id : null)
                    ->disabled(fn () => (bool) auth()->user()?->isDistributor())
                    ->dehydrated(),
                Forms\Components\Select::make('assigned_to')->label('Assigned to')
                    ->visible(fn () => ! auth()->user()?->isDistributor())
                    ->relationship(
                        'assignee',
                        'name',
                        fn ($query) => $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['support', 'admin', 'super_admin']))
                    )
                    ->native(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ticket_no')->label('Ticket #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(45)->wrap(),
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('category')
                    ->formatStateUsing(fn (?string $state) => SupportTicket::CATEGORIES[$state] ?? $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('priority')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'low' => 'gray',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (?string $state) => $state === 'open' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('assignee.name')->label('Assigned')->toggleable(),
                Tables\Columns\TextColumn::make('replies_count')->counts('replies')->label('Replies'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('priority')->options(SupportTicket::PRIORITIES),
                Tables\Filters\SelectFilter::make('assigned_to')->label('Assigned to')->relationship('assignee', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('close')
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (SupportTicket $r) => $r->status === 'open' && ! auth()->user()?->isDistributor())
                    ->requiresConfirmation()
                    ->action(fn (SupportTicket $r) => $r->update([
                        'status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now(),
                    ])),
                Tables\Actions\Action::make('reopen')
                    ->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (SupportTicket $r) => $r->status === 'closed' && ! auth()->user()?->isDistributor())
                    ->requiresConfirmation()
                    ->action(fn (SupportTicket $r) => $r->update([
                        'status' => 'open', 'closed_by' => null, 'closed_at' => null,
                    ])),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RepliesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'create' => Pages\CreateSupportTicket::route('/create'),
            'edit' => Pages\EditSupportTicket::route('/{record}/edit'),
        ];
    }
}
