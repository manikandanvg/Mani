<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\MeetingResource\Pages;
use App\Models\Meeting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * HQ scheduling of live meetings (Phase 6a). The app lists these and deep-links
 * into `join_url`. Head-office only.
 */
class MeetingResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = Meeting::class;

    protected static ?string $navigationGroup = 'Community';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Live Meetings';

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\Textarea::make('description')->rows(3)->columnSpanFull(),
            Forms\Components\TextInput::make('join_url')
                ->label('Join URL')->url()->maxLength(800)->columnSpanFull()
                ->placeholder('https://zoom.us/j/...')
                // Zoom + API configured → the meeting is created AT Zoom on save
                // and this fills itself; otherwise the URL must be pasted in.
                ->required(fn (Forms\Get $get) => ! ($get('platform') === 'zoom'
                    && app(\App\Services\Zoom\ZoomApiService::class)->configured()))
                ->helperText(fn (Forms\Get $get) => $get('platform') === 'zoom'
                    && app(\App\Services\Zoom\ZoomApiService::class)->configured()
                        ? __('Leave blank — saving creates the meeting at Zoom and fills the link, ID and passcode automatically.')
                        : null),
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\Select::make('platform')
                    ->options(['zoom' => 'Zoom', 'meet' => 'Google Meet', 'other' => 'Other'])->default('zoom')->live(),
                Forms\Components\TextInput::make('meeting_id')->maxLength(60),
                Forms\Components\TextInput::make('passcode')->maxLength(60),
                Forms\Components\DateTimePicker::make('scheduled_at')->required()->seconds(false),
                Forms\Components\TextInput::make('duration_min')->label('Duration (min)')->numeric()->default(60),
                Forms\Components\TextInput::make('host_name')->label('Host'),
                Forms\Components\Select::make('visibility')
                    ->options(['members' => 'Distributors only', 'public' => 'Everyone'])->default('members')->required()->live(),
                // Board phase 2 (2026-08-28): the audience is a MULTI-select of exact ranks —
                // e.g. Taluk Admin AND State Admin in the same meeting. Empty = every
                // distributor. The app list AND the schedule push both honour this.
                Forms\Components\Select::make('audience_ranks')
                    ->label('Audience (ranks)')
                    ->multiple()
                    ->options(fn () => static::rankOptions())
                    ->placeholder('All distributors')
                    ->visible(fn (Forms\Get $get) => $get('visibility') === 'members')
                    ->helperText('Pick one or more ranks; leave empty for every distributor. The schedule notification goes only to this audience.')
                    ->columnSpan(2),
                Forms\Components\Toggle::make('is_published')->default(true),
            ]),
        ]);
    }

    /** Rank depth → name, from the ranks master (depth 0 = plain Distributor). */
    public static function rankOptions(): array
    {
        return \App\Models\Rank::orderBy('depth')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->depth => \App\Support\Translatable::pick($r->name) ?: ('Rank ' . $r->depth)])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->limit(40)->searchable()->wrap(),
                Tables\Columns\TextColumn::make('platform')->badge(),
                Tables\Columns\TextColumn::make('scheduled_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('duration_min')->label('Min')->numeric(),
                Tables\Columns\TextColumn::make('visibility')->badge(),
                Tables\Columns\TextColumn::make('audience_ranks')->label('Audience')
                    ->state(fn (Meeting $r) => $r->audienceDepths())
                    ->formatStateUsing(fn ($state) => static::rankOptions()[(int) $state] ?? $state)
                    ->badge()->color('gray')
                    ->placeholder('All distributors'),
                // Distinct people, not rows: a member with both an app-join row and a
                // Zoom row counts once; unmatched Zoom participants count by name.
                Tables\Columns\TextColumn::make('attended')
                    ->label('Attended')->badge()->color('success')
                    ->getStateUsing(fn (Meeting $m) => $m->uniqueAttendeeCount()),
                Tables\Columns\IconColumn::make('is_published')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->options(['zoom' => 'Zoom', 'meet' => 'Google Meet', 'other' => 'Other']),
                // Board 2026-08-23: "how many joined in a date interval" — filter the
                // meetings by schedule window; the Attended badge gives the per-meeting count.
                Tables\Filters\Filter::make('scheduled')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Scheduled from'),
                        Forms\Components\DatePicker::make('to')->label('Scheduled to'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('scheduled_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('scheduled_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'From ' . $data['from'] : null,
                        ($data['to'] ?? null) ? 'To ' . $data['to'] : null,
                    ])),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            MeetingResource\RelationManagers\AttendancesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMeetings::route('/'),
            'create' => Pages\CreateMeeting::route('/create'),
            'edit' => Pages\EditMeeting::route('/{record}/edit'),
        ];
    }
}
