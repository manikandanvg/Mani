<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\MemoResource\Pages;
use App\Models\Memo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Community → Memo (board phase 2, 2026-08-28; replaces the old "Messages" scaffold).
 * Saving a memo pushes it to EVERY app-registered distributor (FCM + in-app inbox)
 * at once; the table keeps the history with how many members were reached.
 */
class MemoResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = Memo::class;

    protected static ?string $navigationGroup = 'Community';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Memo';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    public static function getModelLabel(): string
    {
        return __('Memo');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Memos');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->maxLength(200)->columnSpanFull(),
            Forms\Components\Textarea::make('body')->label('Memo')->required()->rows(6)->columnSpanFull()
                ->helperText('Sent as a push notification to all distributors the moment you save.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->wrap()->weight('semibold'),
                Tables\Columns\TextColumn::make('body')->limit(80)->wrap()->searchable(),
                Tables\Columns\TextColumn::make('sent_count')->label('Reached')->numeric()
                    ->description(fn (Memo $r) => $r->sent_at ? __('members') : null),
                Tables\Columns\TextColumn::make('creator.name')->label('By')->toggleable(),
                Tables\Columns\TextColumn::make('sent_at')->label('Sent')->dateTime('d M Y H:i')->sortable()
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalWidth('2xl'),
                // Re-send to everyone (e.g. after new members joined) — explicit, confirmed.
                Tables\Actions\Action::make('resend')
                    ->label('Send again')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalDescription('Push this memo to every app-registered distributor again?')
                    ->action(function (Memo $record) {
                        $n = static::broadcast($record);
                        \Filament\Notifications\Notification::make()->success()
                            ->title("Memo sent — {$n} member(s) notified")->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Push + inbox to every active app-registered member; stamps the memo. */
    public static function broadcast(Memo $memo): int
    {
        $n = \App\Services\Push\Notifier::broadcast('memo', $memo->title, $memo->body, route: '/inbox');
        $memo->update(['sent_count' => $n, 'sent_at' => now()]);

        return $n;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemos::route('/'),
            'create' => Pages\CreateMemo::route('/create'),
        ];
    }
}
