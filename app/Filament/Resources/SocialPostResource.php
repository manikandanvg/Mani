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

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('author_id')
                    ->required()
                    ->numeric(),
                Forms\Components\Textarea::make('body')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('visibility')
                    ->required(),
                Forms\Components\TextInput::make('like_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('comment_count')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('author_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('visibility'),
                Tables\Columns\TextColumn::make('like_count')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('comment_count')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
