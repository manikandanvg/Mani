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

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Community Posts';

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
                        ->options(['public' => 'Everyone', 'members' => 'Distributors only', 'private' => 'Private'])
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
                // Who wrote it — HQ announcement vs a member's own post (item 8).
                Tables\Columns\TextColumn::make('poster.name')
                    ->label('Author')
                    ->state(fn (SocialPost $r) => $r->poster_id
                        ? ($r->poster?->name ?: 'Member') . ' (member)'
                        : ($r->author?->name ?: 'Lord ICL') . ' (HQ)'),
                Tables\Columns\TextColumn::make('title')->limit(40)->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('body')->limit(50)->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('visibility')->badge(),
                Tables\Columns\IconColumn::make('is_hidden')
                    ->label('Hidden')->boolean()
                    ->trueIcon('heroicon-m-eye-slash')->trueColor('danger')
                    ->falseIcon('heroicon-m-eye')->falseColor('success'),
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
                    ->options(['public' => 'Everyone', 'members' => 'Distributors only', 'private' => 'Private']),
                Tables\Filters\TernaryFilter::make('is_hidden')->label('Hidden'),
                Tables\Filters\TernaryFilter::make('member_posts')
                    ->label('Member posts')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('poster_id'),
                        false: fn (Builder $q) => $q->whereNull('poster_id'),
                    ),
            ])
            ->actions([
                // Moderation (item 8): hide keeps the row for the record; the app stops showing it.
                Tables\Actions\Action::make('toggleHidden')
                    ->label(fn (SocialPost $r) => $r->is_hidden ? 'Unhide' : 'Hide')
                    ->icon(fn (SocialPost $r) => $r->is_hidden ? 'heroicon-m-eye' : 'heroicon-m-eye-slash')
                    ->color(fn (SocialPost $r) => $r->is_hidden ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->action(fn (SocialPost $r) => $r->update(['is_hidden' => ! $r->is_hidden])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            RelationManagers\CommentsRelationManager::class,
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
