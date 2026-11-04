<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\RankChangeResource\Pages;
use App\Models\Rank;
use App\Models\RankChange;
use App\Support\Money;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * System → Rank Changes (user 2026-11-04): the register of every TBP-stage shift — who moved
 * from which stage to which, up or down, on what date, with the ranking BV at that moment and
 * what caused it (billing, cut-off recompute, invoice cancel, or the one-time calibration from
 * bond history). Read-only; exportable through the table toolbar.
 */
class RankChangeResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = RankChange::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Rank Changes';

    protected static ?string $modelLabel = 'Rank change';

    protected static ?string $pluralModelLabel = 'Rank Changes';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'rank-changes';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function rankLabel(?Rank $rank): string
    {
        return $rank ? (Translatable::pick($rank->name) ?: $rank->code) : '—';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['member.branch', 'fromRank', 'toRank']))
            ->defaultSort('changed_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('changed_at')->label('Date')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')->searchable()->sortable()
                    ->description(fn (RankChange $r) => $r->member?->name),
                Tables\Columns\TextColumn::make('member.phone')->label('Phone')->searchable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('member.branch.name')->label('Branch')->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('fromRank.code')->label('From')->badge()->color('gray')
                    ->formatStateUsing(fn ($state, RankChange $r) => static::rankLabel($r->fromRank))->placeholder('—'),
                Tables\Columns\TextColumn::make('toRank.code')->label('To')->badge()
                    ->formatStateUsing(fn ($state, RankChange $r) => static::rankLabel($r->toRank))
                    ->color(fn (RankChange $r) => $r->direction === 'up' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('direction')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'up' ? 'Promoted' : 'Demoted')
                    ->color(fn ($state) => $state === 'up' ? 'success' : 'danger')->sortable(),
                Tables\Columns\TextColumn::make('unpure_bv')->label('Own BV')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('unpure_gbv')->label('Group BV')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('source')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => RankChange::SOURCES[$state] ?? ucfirst((string) $state))->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('changed_at')->label('Date interval')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('changed_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('changed_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'From ' . $data['from'] : null,
                        ($data['to'] ?? null) ? 'To ' . $data['to'] : null,
                    ])),
                Tables\Filters\SelectFilter::make('to_rank_id')->label('Rank reached')->multiple()
                    ->options(fn () => Rank::orderBy('depth')->get()->mapWithKeys(fn (Rank $r) => [$r->id => static::rankLabel($r)])->all()),
                Tables\Filters\SelectFilter::make('direction')->options(['up' => 'Promoted', 'down' => 'Demoted']),
                Tables\Filters\SelectFilter::make('source')->options(RankChange::SOURCES)->multiple(),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRankChanges::route('/'),
        ];
    }
}
