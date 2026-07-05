<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\SocialPostResource\Pages;
use App\Filament\Resources\SocialPostResource\RelationManagers;
use App\Models\SocialPost;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SocialPostResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = SocialPost::class;

    protected static ?string $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // The post is authored by the current HQ user; hidden + auto-filled.
                Forms\Components\Hidden::make('author_id')->default(fn () => auth()->id()),
                Forms\Components\TextInput::make('title')
                    ->maxLength(255)
                    ->placeholder('Headline (optional)')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('body')
                    ->required()
                    ->rows(5)
                    ->columnSpanFull(),
                Forms\Components\Section::make()->columns(3)->schema([
                    Forms\Components\Select::make('visibility')
                        ->options(['public' => 'Everyone', 'members' => 'Members only', 'private' => 'Private'])
                        ->default('members')
                        ->required(),
                    Forms\Components\Toggle::make('pinned')->default(false),
                    Forms\Components\DateTimePicker::make('published_at')
                        ->label('Publish at')
                        ->helperText('Leave empty to publish now.'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\IconColumn::make('pinned')->boolean(),
                Tables\Columns\TextColumn::make('title')->limit(40)->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('body')->limit(50)->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('visibility')->badge(),
                Tables\Columns\TextColumn::make('reactions_count')->counts('reactions')->label('Likes'),
                Tables\Columns\TextColumn::make('app_comments_count')->counts('appComments')->label('Comments'),
                Tables\Columns\TextColumn::make('published_at')->dateTime()->placeholder('Now')->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('visibility')
                    ->options(['public' => 'Everyone', 'members' => 'Members only', 'private' => 'Private']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialPosts::route('/'),
            'create' => Pages\CreateSocialPost::route('/create'),
            'edit' => Pages\EditSocialPost::route('/{record}/edit'),
        ];
    }
}
