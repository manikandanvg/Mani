<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\MessageResource\Pages;
use App\Filament\Resources\MessageResource\RelationManagers;
use App\Models\Message;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MessageResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Message::class;

    protected static ?string $navigationGroup = 'Community';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('conversation_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('sender_id')
                    ->required()
                    ->numeric(),
                Forms\Components\Textarea::make('body')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('attachment_path')
                    ->maxLength(255),
                Forms\Components\TextInput::make('attachment_type')
                    ->maxLength(60),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('conversation_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sender_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('attachment_path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('attachment_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
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
            'index' => Pages\ListMessages::route('/'),
            'create' => Pages\CreateMessage::route('/create'),
            'edit' => Pages\EditMessage::route('/{record}/edit'),
        ];
    }
}
