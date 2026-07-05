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

    protected static ?string $navigationLabel = 'Live Meetings';

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\Textarea::make('description')->rows(3)->columnSpanFull(),
            Forms\Components\TextInput::make('join_url')
                ->label('Join URL')->url()->required()->maxLength(800)->columnSpanFull()
                ->placeholder('https://zoom.us/j/...'),
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\Select::make('platform')
                    ->options(['zoom' => 'Zoom', 'meet' => 'Google Meet', 'other' => 'Other'])->default('zoom'),
                Forms\Components\TextInput::make('meeting_id')->maxLength(60),
                Forms\Components\TextInput::make('passcode')->maxLength(60),
                Forms\Components\DateTimePicker::make('scheduled_at')->required()->seconds(false),
                Forms\Components\TextInput::make('duration_min')->label('Duration (min)')->numeric()->default(60),
                Forms\Components\TextInput::make('host_name')->label('Host'),
                Forms\Components\Select::make('visibility')
                    ->options(['members' => 'Members only', 'public' => 'Everyone'])->default('members')->required(),
                Forms\Components\Toggle::make('is_published')->default(true),
            ]),
        ]);
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
                Tables\Columns\IconColumn::make('is_published')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->options(['zoom' => 'Zoom', 'meet' => 'Google Meet', 'other' => 'Other']),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
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
