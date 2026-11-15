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
                ->helperText('Sent as a push notification + inbox message the moment you save.'),
            // User 2026-11-15: who gets it — everyone, dealers only, chosen ranks (like a Zoom
            // meeting) or particular distributors.
            Forms\Components\Radio::make('audience')
                ->label('Send to')
                ->options(Memo::AUDIENCES)
                ->default('all')->required()->live()->columnSpanFull(),
            Forms\Components\Select::make('audience_ranks')
                ->label('Ranks')
                ->multiple()
                ->options(fn () => MeetingResource::rankOptions())
                ->visible(fn (Forms\Get $get) => $get('audience') === 'ranks')
                ->required(fn (Forms\Get $get) => $get('audience') === 'ranks')
                ->helperText('Pick one or more ranks — only distributors at those ranks receive the memo.')
                ->columnSpanFull(),
            Forms\Components\Select::make('audience_members')
                ->label('Distributors')
                ->multiple()
                ->searchable()
                ->searchPrompt('Type a distributor code, name or phone…')
                ->getSearchResultsUsing(fn (string $search) => \App\Models\Member::query()
                    ->where(fn ($w) => $w->where('member_code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orderBy('member_code')->limit(30)->get()
                    ->mapWithKeys(fn ($m) => [$m->id => $m->member_code . ' — ' . $m->name . ($m->phone ? ' · ' . $m->phone : '')])->all())
                ->getOptionLabelsUsing(fn (array $values) => \App\Models\Member::whereIn('id', $values)->get()
                    ->mapWithKeys(fn ($m) => [$m->id => $m->member_code . ' — ' . $m->name])->all())
                ->visible(fn (Forms\Get $get) => $get('audience') === 'members')
                ->required(fn (Forms\Get $get) => $get('audience') === 'members')
                ->columnSpanFull(),
            Forms\Components\Placeholder::make('audience_reach')
                ->label('Will reach')
                ->content(fn (Forms\Get $get) => static::reachText($get('audience'), $get('audience_ranks'), $get('audience_members')))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->wrap()->weight('semibold'),
                Tables\Columns\TextColumn::make('body')->limit(80)->wrap()->searchable(),
                Tables\Columns\TextColumn::make('audience')->label('Sent to')->badge()->color('gray')
                    ->formatStateUsing(fn ($state, Memo $r) => $r->audienceLabel())->wrap(),
                Tables\Columns\TextColumn::make('sent_count')->label('Reached')->numeric()
                    ->description(fn (Memo $r) => $r->sent_at ? __('members') : null),
                Tables\Columns\TextColumn::make('creator.name')->label('By')->toggleable(),
                Tables\Columns\TextColumn::make('sent_at')->label('Sent')->dateTime('d M Y H:i')->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('audience')->label('Sent to')
                    ->options(['all' => 'All distributors', 'dealers' => 'Dealers only', 'ranks' => 'By rank', 'members' => 'Particular distributors']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalWidth('2xl'),
                // Re-send to the same audience (e.g. after new members joined) — explicit, confirmed.
                Tables\Actions\Action::make('resend')
                    ->label('Send again')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Memo $record) => 'Push this memo again to: ' . $record->audienceLabel() . '?')
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

    /** Push + inbox to the memo's audience (active, app-registered members); stamps the memo. */
    public static function broadcast(Memo $memo): int
    {
        $n = ($memo->audience ?: 'all') === 'all'
            ? \App\Services\Push\Notifier::broadcast('memo', $memo->title, $memo->body, route: '/inbox')
            : \App\Services\Push\Notifier::toQuery($memo->audienceQuery(), 'memo', $memo->title, $memo->body, route: '/inbox');
        $memo->update(['sent_count' => $n, 'sent_at' => now()]);

        return $n;
    }

    /** "1,240 distributors in this audience · 312 of them use the app (they receive it)". */
    protected static function reachText(?string $audience, $ranks, $members): string
    {
        $memo = new Memo(['audience' => $audience ?: 'all', 'audience_ranks' => (array) $ranks, 'audience_members' => (array) $members]);
        if (in_array($memo->audience, ['ranks', 'members'], true) && ! array_filter($memo->audience === 'ranks' ? (array) $ranks : (array) $members)) {
            return '— choose the ' . ($memo->audience === 'ranks' ? 'ranks' : 'distributors') . ' above';
        }
        $total = (clone $memo->audienceQuery())->where('status', 'active')->count();
        $app = (clone $memo->audienceQuery())->where('status', 'active')->whereHas('deviceTokens')->count();

        return number_format($total) . ' active distributor(s) in this audience · ' . number_format($app)
            . ' of them use the app and will receive it (push + inbox).';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemos::route('/'),
            'create' => Pages\CreateMemo::route('/create'),
        ];
    }
}
