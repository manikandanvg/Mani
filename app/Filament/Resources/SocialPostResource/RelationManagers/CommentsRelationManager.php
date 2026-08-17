<?php

namespace App\Filament\Resources\SocialPostResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Comment moderation (board 2026-08-12 item 8): the closed social feed is
 * admin-controlled — hide keeps the row, delete removes it.
 */
class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appComments';

    protected static ?string $title = 'Comments';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('author.name')->label('Author')->placeholder('Member'),
                Tables\Columns\TextColumn::make('body')->wrap()->limit(120),
                Tables\Columns\IconColumn::make('is_hidden')
                    ->label('Hidden')->boolean()
                    ->trueIcon('heroicon-m-eye-slash')->trueColor('danger')
                    ->falseIcon('heroicon-m-eye')->falseColor('success'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('toggleHidden')
                    ->label(fn ($record) => $record->is_hidden ? 'Unhide' : 'Hide')
                    ->icon(fn ($record) => $record->is_hidden ? 'heroicon-m-eye' : 'heroicon-m-eye-slash')
                    ->color(fn ($record) => $record->is_hidden ? 'success' : 'danger')
                    ->action(fn ($record) => $record->update(['is_hidden' => ! $record->is_hidden])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
            ]);
    }
}
