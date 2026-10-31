<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Member;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * System → Activity Log (board 2026-10-31): Head Office's read-only viewer over every
 * logged activity — data changes, admin actions, app requests, logins, jobs and all
 * communications — filterable by category / action / channel / status / actor /
 * distributor / branch / date, searchable, exportable through the table toolbar.
 */
class ActivityLogResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $pluralModelLabel = 'Activity Log';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 90;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('category')->badge()
                    ->formatStateUsing(fn (?string $state) => ActivityLog::categoryLabel($state))
                    ->color(fn (?string $state) => match ($state) {
                        'communication', 'otp' => 'info',
                        'auth' => 'warning',
                        'error' => 'danger',
                        'sales', 'commission', 'wallet' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('action')->badge()->color('gray')->searchable(),
                Tables\Columns\TextColumn::make('summary')->searchable()->wrap()->limit(140)
                    ->description(fn (ActivityLog $r) => $r->subject_label ? ($r->subject_type ? class_basename($r->subject_type) . ' · ' : '') . $r->subject_label : null),
                Tables\Columns\TextColumn::make('actor_name')->label('By')->searchable()
                    ->description(fn (ActivityLog $r) => $r->actor_type)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')->searchable()
                    ->description(fn (ActivityLog $r) => $r->member?->name)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('channel')->badge()->color('gray')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) { 'ok' => 'success', 'denied' => 'warning', default => 'danger' }),
                Tables\Columns\TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('request_id')->label('Request')->toggleable(isToggledHiddenByDefault: true)->copyable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')->multiple()->options(ActivityLog::CATEGORIES),
                Tables\Filters\SelectFilter::make('status')->options(['ok' => 'OK', 'failed' => 'Failed', 'denied' => 'Denied']),
                Tables\Filters\SelectFilter::make('channel')->options([
                    'push' => 'Push', 'inbox' => 'Inbox', 'whatsapp' => 'WhatsApp', 'api' => 'App (API)', 'panel' => 'Admin panel', 'console' => 'Console job',
                ]),
                Tables\Filters\SelectFilter::make('actor_type')->label('Actor')->options([
                    'admin' => 'Admin / dealer login', 'member' => 'Distributor (app)', 'device' => 'L-BOX', 'system' => 'System', 'guest' => 'Guest',
                ]),
                Tables\Filters\SelectFilter::make('member_id')->label('Distributor')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Member::query()
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('member_code', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                        ->limit(50)->get()->mapWithKeys(fn ($m) => [$m->id => "{$m->name} ({$m->member_code})"])->all())
                    ->getOptionLabelUsing(fn ($value) => ($m = Member::find($value)) ? "{$m->name} ({$m->member_code})" : null),
                Tables\Filters\SelectFilter::make('branch_id')->label('Branch')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))->searchable(),
                Tables\Filters\Filter::make('action_text')->label('Action contains')
                    ->form([Forms\Components\TextInput::make('action')->label('Action contains')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', "%{$v}%")))
                    ->indicateUsing(fn (array $data) => filled($data['action'] ?? null) ? ['Action: ' . $data['action']] : []),
                Tables\Filters\Filter::make('occurred')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From')->native(false)->default(now()->subDays(7)->toDateString()),
                        Forms\Components\DatePicker::make('to')->label('To')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v . ' 00:00:00'))
                        ->when($data['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', $v . ' 23:59:59')))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'From ' . \Illuminate\Support\Carbon::parse($data['from'])->format('d M Y') : null,
                        ($data['to'] ?? null) ? 'To ' . \Illuminate\Support\Carbon::parse($data['to'])->format('d M Y') : null,
                    ])),
            ])
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\ViewAction::make()->label('Details')->modalWidth('3xl'),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('occurred_at')->label('When')->dateTime('d M Y H:i:s'),
                Infolists\Components\TextEntry::make('category')->formatStateUsing(fn (?string $state) => ActivityLog::categoryLabel($state))->badge(),
                Infolists\Components\TextEntry::make('action')->badge()->color('gray'),
                Infolists\Components\TextEntry::make('summary')->columnSpanFull(),
                Infolists\Components\TextEntry::make('actor_name')->label('By')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, ActivityLog $r) => trim(($state ?? '') . ' [' . ($r->actor_type ?? '—') . ']')),
                Infolists\Components\TextEntry::make('member.name')->label('Distributor')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, ActivityLog $r) => $state ? $state . ' (' . $r->member?->member_code . ')' : null),
                Infolists\Components\TextEntry::make('branch.name')->label('Branch')->placeholder('—'),
                Infolists\Components\TextEntry::make('subject_label')->label('Subject')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, ActivityLog $r) => ($r->subject_type ? class_basename($r->subject_type) . ' · ' : '') . $state),
                Infolists\Components\TextEntry::make('channel')->placeholder('—')->badge()->color('gray'),
                Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('ip')->label('IP')->placeholder('—'),
                Infolists\Components\TextEntry::make('request_id')->label('Request')->placeholder('—')->copyable(),
                Infolists\Components\TextEntry::make('details')->label('Details')->columnSpanFull()
                    ->state(fn (ActivityLog $r) => $r->details
                        ? new HtmlString('<pre style="white-space:pre-wrap;font-size:.78rem;line-height:1.35;background:#f8f7f4;border:1px solid #e7e5e4;border-radius:.5rem;padding:.75rem;max-height:28rem;overflow:auto">'
                            . e(json_encode($r->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>')
                        : '—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
