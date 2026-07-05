<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\SupportThreadResource\Pages;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Services\Support\SupportService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * HQ side of app support chat (Phase 4c). Lists threads opened by members/customers
 * and lets support read the transcript and reply — each reply notifies the user
 * (inbox + push) via SupportService. Head-office only.
 */
class SupportThreadResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = SupportThread::class;

    protected static ?string $navigationGroup = 'Members';

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
                Tables\Columns\TextColumn::make('subject')->limit(40)->wrap(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === 'open' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('messages_count')->counts('messages')->label('Msgs'),
                Tables\Columns\TextColumn::make('last_message_at')->dateTime()->sortable()->label('Last activity'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Closed']),
            ])
            ->actions([
                Tables\Actions\Action::make('reply')
                    ->label('View & Reply')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->modalHeading(fn (SupportThread $record) => 'Support · ' . static::ownerLabel($record))
                    ->modalContent(fn (SupportThread $record) => static::transcript($record))
                    ->form([
                        Forms\Components\Textarea::make('body')
                            ->label('Your reply')->required()->rows(4)->maxLength(4000),
                    ])
                    ->modalSubmitActionLabel('Send reply')
                    ->action(function (SupportThread $record, array $data) {
                        app(SupportService::class)->supportReply($record, $data['body'], auth()->id());
                        app(SupportService::class)->markSupportRead($record);
                        Notification::make()->success()->title('Reply sent')->send();
                    }),
                Tables\Actions\Action::make('toggle')
                    ->label(fn (SupportThread $record) => $record->status === 'open' ? 'Close' : 'Reopen')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (SupportThread $record) => $record->update([
                        'status' => $record->status === 'open' ? 'closed' : 'open',
                    ])),
            ])
            ->bulkActions([]);
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

    /** Rendered transcript shown above the reply box in the modal. */
    protected static function transcript(SupportThread $thread): HtmlString
    {
        $rows = '';
        foreach ($thread->messages()->orderBy('created_at')->get() as $m) {
            /** @var SupportMessage $m */
            $isSupport = $m->sender === 'support';
            $align = $isSupport ? 'right' : 'left';
            $bg = $isSupport ? '#ab222f' : '#f1f1f1';
            $fg = $isSupport ? '#fff' : '#111';
            $who = $isSupport ? 'HQ' : 'User';
            $when = optional($m->created_at)->format('d M, H:i');
            $rows .= '<div style="text-align:' . $align . ';margin:6px 0">'
                . '<div style="display:inline-block;max-width:80%;background:' . $bg . ';color:' . $fg
                . ';padding:8px 12px;border-radius:12px;text-align:left">'
                . '<div style="font-size:11px;opacity:.7;margin-bottom:2px">' . $who . ' · ' . $when . '</div>'
                . nl2br(e($m->body))
                . '</div></div>';
        }
        if ($rows === '') {
            $rows = '<div style="color:#888">No messages.</div>';
        }

        return new HtmlString('<div style="max-height:360px;overflow-y:auto;padding:4px">' . $rows . '</div>');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportThreads::route('/'),
        ];
    }
}
