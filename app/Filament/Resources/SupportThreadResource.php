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

    /**
     * Optimisation 2026-11-25: the owner, "staff replied" and the last message come with the list
     * query — they were three extra queries for every chat row on the page.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('owner')
            ->withExists(['messages as staff_replied' => fn ($q) => $q->where('sender', 'support')
                ->where('source', '!=', \App\Services\Support\SupportBot::SOURCE)])
            ->addSelect(['last_body' => \App\Models\SupportMessage::select('body')
                ->whereColumn('thread_id', 'support_threads.id')->latest('id')->limit(1)]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('owner_label')
                    ->label('From')
                    ->state(fn (SupportThread $r) => static::ownerLabel($r))
                    // User 2026-11-20: find a chat by the person's name, phone number or distributor ID
                    ->searchable(query: fn (\Illuminate\Database\Eloquent\Builder $query, string $search) => static::searchOwner($query, $search)),
                // Triage (2026-11-18): what the chat is about, worked out from the user's own words
                Tables\Columns\TextColumn::make('topic')->badge()->color('info')->sortable()
                    ->formatStateUsing(fn (?string $state) => \Illuminate\Support\Str::before(\App\Services\Support\SupportBot::options()[$state] ?? '—', ' /')),
                // Whose turn is it? The user wrote last = the desk owes a reply — also after an
                // auto reply, when the user answers it with the details that were asked for.
                Tables\Columns\TextColumn::make('turn')->label('Waiting for')->badge()
                    ->state(fn (SupportThread $r) => $r->needs_staff ? 'Us' : 'User')
                    ->color(fn (string $state) => $state === 'Us' ? 'danger' : 'gray'),
                Tables\Columns\IconColumn::make('answered')->label('Staff replied')->boolean()
                    ->state(fn (SupportThread $r) => (bool) $r->staff_replied),
                Tables\Columns\TextColumn::make('subject')->limit(40)->wrap()->searchable(),
                Tables\Columns\TextColumn::make('last_message')->label('Last message')
                    ->state(fn (SupportThread $r) => \Illuminate\Support\Str::limit((string) $r->last_body, 70))
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
                Tables\Filters\SelectFilter::make('topic')
                    ->options(fn () => collect(config('support_replies.templates', []))->filter(fn ($t) => ! empty($t['match']) || ($t['bot'] ?? false))
                        ->map(fn ($t) => $t['title'])->all()),
                // The working queue: chats where the USER wrote last. A chat comes back here by itself
                // when the user answers a reply (an auto reply included).
                Tables\Filters\TernaryFilter::make('turn')
                    ->label('Waiting for')
                    ->placeholder('All chats')->trueLabel('Waiting for us (needs a staff reply)')->falseLabel('Waiting for the user')
                    ->queries(
                        true: fn ($q) => $q->where('needs_staff', true),
                        false: fn ($q) => $q->where('needs_staff', false),
                    ),
                Tables\Filters\TernaryFilter::make('answered')
                    ->label('Replied by staff')
                    ->placeholder('All chats')->trueLabel('A staff member has replied')->falseLabel('No staff reply yet (auto reply does not count)')
                    ->queries(
                        true: fn ($q) => $q->whereHas('messages', fn ($m) => $m->where('sender', 'support')->where('source', '!=', \App\Services\Support\SupportBot::SOURCE)),
                        false: fn ($q) => $q->whereDoesntHave('messages', fn ($m) => $m->where('sender', 'support')->where('source', '!=', \App\Services\Support\SupportBot::SOURCE)),
                    ),
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
                    ->action(function (SupportThread $record) {
                        $record->update(['status' => $record->status === 'open' ? 'closed' : 'open']);
                        // reopened beside another open chat of the same person → one chat (board 2026-11-25)
                        app(\App\Services\Support\SupportService::class)->consolidate($record);
                    }),
            ])
            ->bulkActions([
                // 2026-11-18: filter one topic, tick the chats, answer them all with one ready reply.
                // The text is fixed (config/support_replies.php) — nothing about a member is in it.
                Tables\Actions\BulkAction::make('reply_selected')
                    ->label('Send a ready reply to selected')
                    ->icon('heroicon-o-paper-airplane')
                    ->form([
                        \Filament\Forms\Components\Select::make('template')->label('Ready reply')
                            ->options(fn () => \App\Services\Support\SupportBot::options())->required()->live()
                            ->afterStateUpdated(fn ($state, \Filament\Forms\Set $set) => $set('body', \App\Services\Support\SupportBot::body($state))),
                        \Filament\Forms\Components\Textarea::make('body')->label('Message that will be sent')->rows(10)->required()
                            // one person's detail must never go to many chats: refused, not just warned
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if ($found = \App\Services\Support\SupportBot::personalDetailIn((string) $value)) {
                                    $fail("This message goes to every selected chat, so it cannot contain a distributor ID, phone number or amount (found: {$found}). Reply to that chat alone instead.");
                                }
                            })
                            ->helperText('Every selected chat gets this same message, as a push notification and in the app inbox. A distributor ID, phone number or amount is refused here.'),
                    ])
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records, array $data) {
                        $svc = app(\App\Services\Support\SupportService::class);
                        $records->each(fn (SupportThread $t) => $svc->supportReply($t, trim((string) $data['body']), auth()->id()));
                        \Filament\Notifications\Notification::make()->success()->title($records->count() . ' chat(s) answered')->send();
                    }),
                // Each selected chat that nobody has answered gets the acknowledgement of ITS OWN topic.
                Tables\Actions\BulkAction::make('acknowledge_selected')
                    ->label('Auto-acknowledge selected (by topic)')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalDescription('Each selected chat that has no reply yet gets the ready acknowledgement for its own topic. Chats already answered are skipped.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records) {
                        $bot = app(\App\Services\Support\SupportBot::class);
                        $sent = $records->filter(fn (SupportThread $t) => $bot->acknowledge($t) !== null)->count();
                        \Filament\Notifications\Notification::make()->success()
                            ->title($sent . ' chat(s) acknowledged, ' . ($records->count() - $sent) . ' skipped (already answered)')->send();
                    }),
                // "a lot of support chats are there" — close many in one go
                Tables\Actions\BulkAction::make('close_selected')
                    ->label('Close selected chats')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (\Illuminate\Support\Collection $records) => $records->each->update(['status' => 'closed'])),
            ]);
    }

    /** Chats whose owner (Distributor or Customer) matches every word typed: name, phone or distributor ID. */
    public static function searchOwner(\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder
    {
        $words = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);
        $ids = function (string $model, array $cols) use ($words) {
            $q = $model::query()->select('id');
            foreach ($words as $word) {
                $q->where(fn ($w) => collect($cols)->each(fn ($c) => $w->orWhere($c, 'like', '%' . $word . '%')));
            }

            return $q;
        };

        return $query->where(fn ($q) => $q
            ->where(fn ($m) => $m->where('owner_type', (new \App\Models\Member)->getMorphClass())
                ->whereIn('owner_id', $ids(\App\Models\Member::class, ['name', 'phone', 'member_code'])))
            ->orWhere(fn ($c) => $c->where('owner_type', (new \App\Models\Customer)->getMorphClass())
                ->whereIn('owner_id', $ids(\App\Models\Customer::class, ['name', 'phone']))));
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
