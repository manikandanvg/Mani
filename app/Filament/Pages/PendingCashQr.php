<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\DigiWithdrawal;
use App\Services\Digi\PendingCashQrService;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Commissions → Pending Cash QR (board 2026-11-01). Every legacy digi-gold withdrawal
 * request (and ticket-only rows) waits here with the grams, the rate and the rupee worth
 * fixed on its own date. Head Office decides per row or in bulk: pay the worth to the cash
 * wallet, or put the grams back as a digi-gold purchase at that date's rate. Nothing is
 * settled automatically.
 */
class PendingCashQr extends Page implements HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use HqOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Pending Cash QR';

    protected static ?string $title = 'Pending Cash QR';

    protected static string $view = 'filament.pages.pending-cash-qr';

    public function getSubheading(): ?string
    {
        $p = DigiWithdrawal::where('status', 'pending');

        return number_format((clone $p)->count()) . ' requests waiting · ' . number_format((float) (clone $p)->sum('gold_wt'), 3) . ' g · '
            . Money::inr((float) (clone $p)->sum('worth')) . ' at the rates of their own dates. Decide per row or select several.';
    }

    public static function canDecide(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(DigiWithdrawal::query()->with(['member.branch', 'decidedBy']))
            ->defaultSort('created_at', 'asc')
            ->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Requested on')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')->searchable()->sortable()
                    ->description(fn (DigiWithdrawal $r) => $r->member?->name),
                Tables\Columns\TextColumn::make('member.phone')->label('Phone')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('member.branch.name')->label('Branch')->toggleable(),
                Tables\Columns\TextColumn::make('source')->badge()
                    ->formatStateUsing(fn ($state) => $state === DigiWithdrawal::SOURCE_TICKET ? 'Dealer ticket only' : 'Withdrawal request')
                    ->color(fn ($state) => $state === DigiWithdrawal::SOURCE_TICKET ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('qr_code')->label('Cash QR')->searchable()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('gold_wt')->label('Grams')->numeric(4)->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(4)),
                Tables\Columns\TextColumn::make('rate')->label('Rate ₹/g (that date)')->formatStateUsing(fn ($state) => $state ? Money::inr((float) $state) : '—')->alignEnd(),
                Tables\Columns\TextColumn::make('worth')->label('Worth ₹')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->formatStateUsing(fn ($state) => Money::inr((float) $state))),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (DigiWithdrawal $r) => $r->status === 'pending' ? 'Pending'
                        : ($r->decision === DigiWithdrawal::DECISION_DIGI ? 'Digi-gold' : 'Cash wallet'))
                    ->color(fn (DigiWithdrawal $r) => $r->status === 'pending' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('decided_at')->label('Decided')->dateTime('d M Y H:i')->placeholder('—')->toggleable()
                    ->description(fn (DigiWithdrawal $r) => $r->decidedBy?->name),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'delivered' => 'Decided'])
                    ->default('pending')
                    ->selectablePlaceholder(false),
                Tables\Filters\SelectFilter::make('source')
                    ->options([DigiWithdrawal::SOURCE_REQUEST => 'Withdrawal request', DigiWithdrawal::SOURCE_TICKET => 'Dealer ticket only']),
                Tables\Filters\SelectFilter::make('decision')
                    ->options([DigiWithdrawal::DECISION_CASH => 'Cash wallet', DigiWithdrawal::DECISION_DIGI => 'Digi-gold']),
            ])
            ->actions([
                Tables\Actions\Action::make('toCash')
                    ->label('Cash wallet')->icon('heroicon-o-wallet')->color('success')
                    ->visible(fn (DigiWithdrawal $r) => $r->status === 'pending' && static::canDecide())
                    ->requiresConfirmation()
                    ->modalHeading('Pay this request to the cash wallet?')
                    ->modalDescription(fn (DigiWithdrawal $r) => Money::inr((float) $r->worth) . ' (' . number_format((float) $r->gold_wt, 4) . ' g at ₹' . Money::group((float) $r->rate) . '/g on ' . $r->created_at?->format('d M Y') . ') is credited to ' . ($r->member?->member_code ?? '—') . '. The dealer ticket closes.')
                    ->action(fn (DigiWithdrawal $r) => $this->run(fn () => app(PendingCashQrService::class)->toCash($r, auth()->id()), 1)),
                Tables\Actions\Action::make('toDigi')
                    ->label('Digi-gold')->icon('heroicon-o-sparkles')->color('warning')
                    ->visible(fn (DigiWithdrawal $r) => $r->status === 'pending' && static::canDecide())
                    ->requiresConfirmation()
                    ->modalHeading('Record this request as a digi-gold purchase?')
                    ->modalDescription(fn (DigiWithdrawal $r) => number_format((float) $r->gold_wt, 4) . ' g go back to the digi-gold purse of ' . ($r->member?->member_code ?? '—') . ' at ₹' . Money::group((float) $r->rate) . '/g (' . $r->created_at?->format('d M Y') . '). No cash moves. The dealer ticket closes.')
                    ->action(fn (DigiWithdrawal $r) => $this->run(fn () => app(PendingCashQrService::class)->toDigiGold($r, auth()->id()), 1)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkCash')
                    ->label('Pay selected to cash wallet')->icon('heroicon-o-wallet')->color('success')
                    ->visible(fn () => static::canDecide())
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->run(function () use ($records) {
                        $svc = app(PendingCashQrService::class);
                        $n = 0;
                        foreach ($records as $r) {
                            if ($r->status === 'pending') { $svc->toCash($r, auth()->id()); $n++; }
                        }

                        return $n;
                    })),
                Tables\Actions\BulkAction::make('bulkDigi')
                    ->label('Record selected as digi-gold')->icon('heroicon-o-sparkles')->color('warning')
                    ->visible(fn () => static::canDecide())
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->run(function () use ($records) {
                        $svc = app(PendingCashQrService::class);
                        $n = 0;
                        foreach ($records as $r) {
                            if ($r->status === 'pending') { $svc->toDigiGold($r, auth()->id()); $n++; }
                        }

                        return $n;
                    })),
            ]);
    }

    protected function run(\Closure $fn, ?int $count = null): void
    {
        try {
            $n = $fn();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Not decided')->body($e->getMessage())->send();

            return;
        }
        $n = is_int($n) ? $n : ($count ?? 1);
        Notification::make()->success()->title($n . ' request' . ($n === 1 ? '' : 's') . ' decided')->send();
    }
}
