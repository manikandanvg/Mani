<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\MeetingAttendanceResource\Pages;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Meeting participation log (board 2026-08-23) — every attendance row across
 * every meeting, filterable by date interval / meeting / source / member, so HQ
 * can see who joined what and how many joined in a period. Read-only: rows are
 * written by the app's Join tap and by Zoom's participant webhooks. The global
 * table toolbar provides CSV / XLSX / PDF export.
 */
class MeetingAttendanceResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = MeetingAttendance::class;

    protected static ?string $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Meeting Participation';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $modelLabel = 'Participation';

    protected static ?string $pluralModelLabel = 'Meeting Participation';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('joined_at', 'desc')
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['meeting', 'member']))
            ->columns([
                Tables\Columns\TextColumn::make('joined_at')->label('Joined')->dateTime('d M Y, h:i A')->sortable(),
                Tables\Columns\TextColumn::make('meeting.title')->label('Meeting')->limit(40)->wrap()->searchable()
                    ->description(fn (MeetingAttendance $a) => $a->meeting?->scheduled_at?->format('d M Y, h:i A')),
                Tables\Columns\TextColumn::make('member.member_code')->label('Member')->searchable()->placeholder('—')
                    ->description(fn (MeetingAttendance $a) => $a->member?->name),
                Tables\Columns\TextColumn::make('participant_name')->label('Zoom name')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('source')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'zoom' ? 'Zoom verified' : 'App join')
                    ->color(fn ($state) => $state === 'zoom' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('left_at')->label('Left')->dateTime('d M, h:i A')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('duration_min')->label('Minutes')->numeric()->placeholder('—')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total min')),
            ])
            ->filters([
                Tables\Filters\Filter::make('joined')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Joined from'),
                        Forms\Components\DatePicker::make('to')->label('Joined to'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('joined_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('joined_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'From ' . $data['from'] : null,
                        ($data['to'] ?? null) ? 'To ' . $data['to'] : null,
                    ])),
                Tables\Filters\SelectFilter::make('meeting_id')->label('Meeting')
                    ->options(fn () => Meeting::orderByDesc('scheduled_at')->limit(200)->get()
                        ->mapWithKeys(fn (Meeting $m) => [$m->id => $m->scheduled_at->format('d M Y') . ' — ' . $m->title]))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('source')->options(['app' => 'App join', 'zoom' => 'Zoom verified']),
                Tables\Filters\TernaryFilter::make('member_id')->label('Matched to a member')
                    ->nullable()
                    ->trueLabel('Matched')->falseLabel('Unmatched Zoom name')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('member_id'),
                        false: fn (Builder $q) => $q->whereNull('member_id'),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMeetingAttendances::route('/'),
        ];
    }
}
