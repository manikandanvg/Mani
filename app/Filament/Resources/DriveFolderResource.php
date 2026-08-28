<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\DriveFolderResource\Pages;
use App\Filament\Resources\DriveFolderResource\RelationManagers\FilesRelationManager;
use App\Models\DriveFolder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Community → Training Library (user 2026-08-29, option 1): HQ's file drive, renamed to
 * say what it is for. Folders marked PUBLIC — and the public files inside them — are what
 * distributors see under Library in the app ("Live & Learn" → Learn). Files are uploaded
 * on the folder's page (Files tab). The old raw "Drive Files" list stays reachable by URL
 * but is no longer in the menu.
 */
class DriveFolderResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = DriveFolder::class;

    protected static ?string $navigationGroup = 'Community';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Training Library';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    public const VISIBILITY = ['public' => 'Public — shown in the app Library', 'private' => 'Private — HQ only'];

    public static function getModelLabel(): string
    {
        return __('Library folder');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Training Library');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('parent')->withCount('files');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(200)
                    ->placeholder('e.g. Scheme brochures, Training videos, Product catalogue'),
                Forms\Components\Select::make('parent_id')->label('Inside folder')
                    ->options(fn (?DriveFolder $record) => DriveFolder::orderBy('name')
                        ->when($record, fn ($q) => $q->where('id', '!=', $record->id))->pluck('name', 'id'))
                    ->placeholder('Top level')->searchable()->native(false),
                Forms\Components\Select::make('visibility')->options(self::VISIBILITY)->default('public')->required()->native(false)
                    ->helperText('Only public folders (and public files inside them) appear in the app.'),
                Forms\Components\Hidden::make('owner_id')->default(fn () => auth()->id())->dehydrated(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->icon('heroicon-o-folder'),
                Tables\Columns\TextColumn::make('parent.name')->label('Inside')->placeholder('Top level')->toggleable(),
                Tables\Columns\TextColumn::make('files_count')->label('Files')->badge()->color('gray')->alignCenter(),
                Tables\Columns\TextColumn::make('visibility')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'public' ? 'In the app' : ucfirst((string) $state))
                    ->color(fn ($state) => $state === 'public' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('updated_at')->since()->label('Updated')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('visibility')->options(self::VISIBILITY),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Open')->icon('heroicon-o-folder-open'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [FilesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriveFolders::route('/'),
            'create' => Pages\CreateDriveFolder::route('/create'),
            'edit' => Pages\EditDriveFolder::route('/{record}/edit'),
        ];
    }
}
