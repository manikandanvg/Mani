<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\RankChangeResource;
use App\Models\Member;
use App\Models\Rank;
use App\Support\Money;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * System → Ranked Distributor (user 2026-11-04): every distributor holding a TBP stage, with the
 * date the CURRENT stage was reached (the latest promotion into it on the rank_changes register —
 * billing, cut-off or the calibration from bond history). Filter by achievement date interval and
 * by rank; export through the table toolbar.
 */
class RankedDistributors extends Page implements HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use HqOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Ranked Distributor';

    protected static ?string $title = 'Ranked Distributor';

    protected static ?string $slug = 'ranked-distributors';

    protected static string $view = 'filament.pages.ranked-distributors';

    public function getSubheading(): ?string
    {
        $ranked = Member::query()->whereHas('rank', fn ($q) => $q->where('depth', '>=', 1))->count();

        return number_format($ranked) . ' distributors hold a TBP stage. "Achieved on" is the date the current stage was reached; blank means no register entry yet (run ranks:calibrate once).';
    }

    /** Latest promotion INTO the member's current rank — one row per member, as a sub-select. */
    protected function achievedAtSql(): string
    {
        return '(select max(rc.changed_at) from rank_changes rc where rc.member_id = members.id and rc.to_rank_id = members.rank_id)';
    }

    public function table(Table $table): Table
    {
        $achieved = $this->achievedAtSql();

        return $table
            ->query(Member::query()
                ->select('members.*')
                ->selectRaw($achieved . ' as achieved_at')
                ->with(['rank', 'branch'])
                ->whereHas('rank', fn ($q) => $q->where('depth', '>=', 1)))
            ->defaultSort('achieved_at', 'desc')
            ->paginated([25, 50, 100, 200])
            ->columns([
                Tables\Columns\TextColumn::make('member_code')->label('Distributor ID')->searchable()->sortable()->copyable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->label('Phone')->searchable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('city')->label('City')->searchable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('rank.code')->label('Rank')->badge()->color('success')
                    ->formatStateUsing(fn ($state, Member $r) => RankChangeResource::rankLabel($r->rank))
                    ->sortable(query: fn (Builder $q, string $direction) => $q->orderBy(
                        Rank::select('depth')->whereColumn('ranks.id', 'members.rank_id'), $direction)),
                Tables\Columns\TextColumn::make('achieved_at')->label('Achieved on')->dateTime('d M Y')->sortable()->placeholder('—')
                    ->description(fn (Member $r) => $this->achievedVia($r)),
                Tables\Columns\TextColumn::make('unpure_bv')->label('Own BV')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('unpure_gbv')->label('Group BV')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('joined_on')->label('Joined')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('achieved')->label('Achieved between')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereRaw('DATE(' . $achieved . ') >= ?', [$d]))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereRaw('DATE(' . $achieved . ') <= ?', [$d])))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'Achieved from ' . $data['from'] : null,
                        ($data['to'] ?? null) ? 'Achieved to ' . $data['to'] : null,
                    ])),
                Tables\Filters\SelectFilter::make('rank_id')->label('Rank')->multiple()
                    ->options(fn () => Rank::where('depth', '>=', 1)->orderBy('depth')->get()
                        ->mapWithKeys(fn (Rank $r) => [$r->id => RankChangeResource::rankLabel($r)])->all()),
                Tables\Filters\SelectFilter::make('branch_id')->label('Branch')
                    ->options(fn () => \App\Models\Branch::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\Action::make('history')->label('History')->icon('heroicon-o-clock')
                    ->url(fn (Member $r) => RankChangeResource::getUrl('index', ['tableSearch' => $r->member_code])),
            ])
            ->bulkActions([]);
    }

    protected function achievedVia(Member $r): ?string
    {
        $row = DB::table('rank_changes')->where('member_id', $r->id)->where('to_rank_id', $r->rank_id)
            ->orderByDesc('changed_at')->first(['source', 'from_rank_id']);
        if (! $row) {
            return null;
        }
        $from = $row->from_rank_id ? Rank::find($row->from_rank_id) : null;

        return ($from ? 'from ' . RankChangeResource::rankLabel($from) . ' · ' : '') . (\App\Models\RankChange::SOURCES[$row->source] ?? $row->source);
    }
}
