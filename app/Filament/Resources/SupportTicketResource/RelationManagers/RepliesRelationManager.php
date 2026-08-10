<?php

namespace App\Filament\Resources\SupportTicketResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** The ticket's reply trail — oldest first, like a conversation transcript. */
class RepliesRelationManager extends RelationManager
{
    protected static string $relationship = 'replies';

    protected static ?string $title = 'Replies';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('body')->label('Reply')->required()->rows(4)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i'),
                Tables\Columns\TextColumn::make('user.name')->label('By')->placeholder('—'),
                Tables\Columns\TextColumn::make('body')->label('Reply')->wrap()->limit(500),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add reply')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
            ]);
    }
}
