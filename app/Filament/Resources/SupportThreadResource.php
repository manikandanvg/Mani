<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\SupportDesk;
use App\Filament\Resources\SupportThreadResource\Pages;
use App\Models\SupportThread;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * HQ side of app support chat (Phase 4c). Lists threads opened by members/customers
 * and lets support read the transcript and reply — each reply notifies the user
 * (inbox + push) via SupportService. Head-office only.
 */
class SupportThreadResource extends BaseResource
{
    use SupportDesk;

    protected static ?string $model = SupportThread::class;

    protected static ?string $navigationGroup = 'Support & Track';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Support Chat';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('owner_label')
                    ->label('From')
                    ->state(fn (SupportThread $r) => static::ownerLabel($r))
                    ->searchable(false),
                Tables\Columns\TextColumn::make('subject')->limit(40)->wrap()->searchable(),
                Tables\Columns\TextColumn::make('last_message')->label('Last message')
                    ->state(fn (SupportThread $r) => \Illuminate\Support\Str::limit((string) $r->messages()->latest('id')->value('body'), 70))
                    ->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === 'open' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('messages_count')->counts('messages')->label('Msgs'),
                Tables\Columns\TextColumn::make('last_message_at')->dateTime()->sortable()->label('Last activity'),
            ])
            ->filters([
                // Board 2026-11-15: a CLOSED chat leaves the list; switch this filter to see them.
                Tables\Filters\SelectFilter::make('status')
                    ->options(['open' => 'Open chats', 'closed' => 'Closed chats'])
                    ->default('open'),
            ])
            ->actions([
                // Board 2026-11-15: the chat opens in a POPUP — live transcript, rich-text reply,
                // close / reopen — so the desk never leaves the list.
                Tables\Actions\Action::make('chat')
                    ->label('Open chat')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->modalHeading(fn (SupportThread $record) => 'Chat · ' . static::ownerLabel($record))
                    ->modalWidth('4xl')
                    ->modalContent(fn (SupportThread $record) => view('filament.modals.support-chat', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Tables\Actions\Action::make('toggle')
                    ->label(fn (SupportThread $record) => $record->status === 'open' ? 'Close' : 'Reopen')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (SupportThread $record) => $record->update([
                        'status' => $record->status === 'open' ? 'closed' : 'open',
                    ])),
            ])
            ->bulkActions([
                // "a lot of support chats are there" — close many in one go
                Tables\Actions\BulkAction::make('close_selected')
                    ->label('Close selected chats')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (\Illuminate\Support\Collection $records) => $records->each->update(['status' => 'closed'])),
            ]);
    }

    public static function ownerLabel(SupportThread $thread): string
    {
        $owner = $thread->owner;
        if ($owner === null) {
            return 'Unknown';
        }
        $name = $owner->name ?: 'No name';
        $phone = $owner->phone ?? '';
        $kind = class_basename($thread->owner_type);

        return trim("{$name} · {$phone} ({$kind})");
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportThreads::route('/'),
            'chat' => Pages\ChatSupportThread::route('/{record}/chat'),
        ];
    }
}
