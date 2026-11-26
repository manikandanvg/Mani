<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\DigiWithdrawal;
use App\Models\PendingLegacyQr;
use App\Models\RedeemableQr;
use App\Services\Digi\PendingCashQrService;
use App\Services\Qr\RedeemableQrService;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Commissions → Pending Gold QR (board 2026-11-01, both routes since 2026-11-26).
 *
 *   INVOICE — every legacy invoice QR (gold / silver) still awaiting delivery, carried over
 *             as a redeemable QR with the metal price of its own date. Head Office sends it
 *             over WhatsApp (single or bulk); the distributor redeems it at ANY branch at
 *             that date's rate (Redeem QR screen). No conversion button.
 *   DIGIWD  — every legacy digi-gold withdrawal QR. Head Office sends the grams to the
 *             distributor's digi-gold (gold) wallet as a purchase at the QR-date rate; the
 *             QR then reads "redeemed" (same service as Pending Cash QR → Digi-gold).
 */
class PendingGoldQr extends Page implements HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use HqOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Pending Gold QR';

    protected static ?string $title = 'Pending Gold QR';

    protected static string $view = 'filament.pages.pending-gold-qr';

    public function getSubheading(): ?string
    {
        $p = RedeemableQr::where('source', RedeemableQr::SOURCE_LEGACY)->where('status', 'pending');
        $gold = (clone $p)->where('qr_mode', 'gold');
        $silver = (clone $p)->where('qr_mode', 'silver');
        $wd = DigiWithdrawal::where('status', 'pending');

        return 'INVOICE: ' . number_format((clone $gold)->count()) . ' gold QRs (' . number_format((float) (clone $gold)->sum('gram_worth'), 3) . ' g, ' . Money::inr((float) (clone $gold)->sum('cash_worth')) . ') · '
            . number_format((clone $silver)->count()) . ' silver QRs (' . number_format((float) (clone $silver)->sum('gram_worth'), 3) . ' g, ' . Money::inr((float) (clone $silver)->sum('cash_worth')) . ') waiting — each redeems at any branch at the metal price of its own date. '
            . 'DIGIWD: ' . number_format((clone $wd)->count()) . ' QRs (' . number_format((float) (clone $wd)->sum('gold_wt'), 3) . ' g, ' . Money::inr((float) (clone $wd)->sum('worth')) . ') waiting — send each to the digi-gold wallet.';
    }

    public static function canSend(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PendingLegacyQr::query()->with(['member', 'bond.plan', 'branch', 'redeemBranch', 'decidedBy']))
            ->defaultSort('priced_on', 'asc')
            ->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('priced_on')->label('QR date (price date)')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('route')->label('Route')->badge()->sortable()
                    ->color(fn ($state) => $state === PendingLegacyQr::ROUTE_DIGIWD ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')->searchable()->sortable()
                    ->description(fn (PendingLegacyQr $r) => $r->member?->name),
                Tables\Columns\TextColumn::make('member.phone')->label('Phone')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('plan')->label('Plan')->toggleable()
                    ->getStateUsing(fn (PendingLegacyQr $r) => $r->isDigiWd() ? '—' : ($r->bond?->plan?->code ?? 'contract deleted in legacy')),
                Tables\Columns\TextColumn::make('invoice_no')->label('Invoice')->searchable()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Billing branch')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('qr_code')->label('QR code')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('qr_mode')->label('Metal')->badge()
                    ->color(fn ($state) => match ($state) { 'silver' => 'gray', 'cash' => 'success', default => 'warning' }),
                Tables\Columns\TextColumn::make('gram_worth')->label('Grams')->numeric(3)->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(3)),
                Tables\Columns\TextColumn::make('frozen_rate')->label('Rate ₹/g (that date)')->formatStateUsing(fn ($state) => $state ? Money::inr((float) $state) : '—')->alignEnd(),
                Tables\Columns\TextColumn::make('cash_worth')->label('Worth ₹')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->formatStateUsing(fn ($state) => Money::inr((float) $state))),
                Tables\Columns\IconColumn::make('qr_sent')->label('Sent')->boolean(),
                Tables\Columns\TextColumn::make('sent_at')->label('Sent at')->dateTime('d M Y H:i')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'pending' ? 'warning' : 'success')
                    ->description(fn (PendingLegacyQr $r) => $r->isDigiWd() && $r->status !== 'pending'
                        ? ($r->decision === DigiWithdrawal::DECISION_DIGI ? 'to digi-gold wallet' : 'to cash wallet') : null),
                Tables\Columns\TextColumn::make('redeemBranch.name')->label('Redeemed at')->toggleable()
                    ->placeholder(fn (PendingLegacyQr $r) => $r->isDigiWd() && $r->status !== 'pending' ? 'Head Office' : '—')
                    ->description(fn (PendingLegacyQr $r) => $r->redeemed_at?->format('d M Y')
                        . ($r->isDigiWd() && $r->decidedBy ? ' · ' . $r->decidedBy->name : '')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'redeemed' => 'Redeemed'])
                    ->default('pending')
                    ->selectablePlaceholder(false),
                Tables\Filters\SelectFilter::make('route')->label('Route')
                    ->options([PendingLegacyQr::ROUTE_INVOICE => 'INVOICE', PendingLegacyQr::ROUTE_DIGIWD => 'DIGIWD']),
                Tables\Filters\SelectFilter::make('qr_mode')->label('Metal')->options(['gold' => 'Gold', 'silver' => 'Silver', 'cash' => 'Cash (DIGIWD)']),
                Tables\Filters\TernaryFilter::make('qr_sent')->label('Sent to distributor'),
            ])
            ->actions([
                // DIGIWD only — the grams go to the digi-gold (gold) wallet as a purchase; the QR reads redeemed
                Tables\Actions\Action::make('toDigi')
                    ->label('Send to Digi-gold')->icon('heroicon-o-sparkles')->color('warning')
                    ->visible(fn (PendingLegacyQr $r) => $r->isDigiWd() && $r->status === 'pending' && static::canSend())
                    ->requiresConfirmation()
                    ->modalHeading('Send this QR to the digi-gold wallet?')
                    ->modalDescription(fn (PendingLegacyQr $r) => number_format((float) $r->gram_worth, 4) . ' g go to the digi-gold (gold) wallet of ' . ($r->member?->member_code ?? '—')
                        . ' as a purchase at ₹' . Money::group((float) $r->frozen_rate) . '/g (' . $r->created_at?->format('d M Y') . '). No cash moves. The QR is marked redeemed.')
                    ->action(function (PendingLegacyQr $r) {
                        try {
                            app(PendingCashQrService::class)->toDigiGold($r->source(), auth()->id());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title(number_format((float) $r->gram_worth, 4) . ' g sent to the digi-gold wallet')->send();
                    }),
                // INVOICE only — the QR travels on WhatsApp and is redeemed at a branch
                Tables\Actions\Action::make('send')
                    ->label(fn (PendingLegacyQr $r) => $r->qr_sent ? 'Resend' : 'Send via WhatsApp')
                    ->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn (PendingLegacyQr $r) => ! $r->isDigiWd() && $r->status === 'pending' && static::canSend())
                    ->requiresConfirmation()
                    ->modalDescription(fn (PendingLegacyQr $r) => 'The QR image and the redeem message go to ' . ($r->member?->phone ?? 'the distributor') . ' on WhatsApp (and to the app inbox).')
                    ->action(function (PendingLegacyQr $r) {
                        $res = app(RedeemableQrService::class)->deliver($r->source(), withContract: false);
                        $res['ok']
                            ? Notification::make()->success()->title('QR ' . $r->qr_code . ' sent')->body($res['message'] ?? '')->send()
                            : Notification::make()->danger()->title('QR ' . $r->qr_code . ' not sent')->body($res['message'] ?? '')->send();
                    }),
                Tables\Actions\Action::make('image')
                    ->label('QR image')->icon('heroicon-o-photo')->color('gray')
                    ->visible(fn (PendingLegacyQr $r) => ! $r->isDigiWd())
                    ->url(fn (PendingLegacyQr $r) => $r->isDigiWd() ? null : app(RedeemableQrService::class)->imageUrl($r->source()), shouldOpenInNewTab: true),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkDigi')
                    ->label('Send selected DIGIWD to Digi-gold')->icon('heroicon-o-sparkles')->color('warning')
                    ->visible(fn () => static::canSend())
                    ->requiresConfirmation()
                    ->modalDescription('Only the DIGIWD rows among the selected are sent to the digi-gold wallet; INVOICE rows are left untouched.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $svc = app(PendingCashQrService::class);
                        [$ok, $fail] = [0, 0];
                        foreach ($records as $r) {
                            if (! $r->isDigiWd() || $r->status !== 'pending') {
                                continue;
                            }
                            try {
                                $svc->toDigiGold($r->source(), auth()->id());
                                $ok++;
                            } catch (\Throwable) {
                                $fail++;
                            }
                        }
                        Notification::make()->{$fail ? 'warning' : 'success'}()
                            ->title($ok . ' QR' . ($ok === 1 ? '' : 's') . ' sent to the digi-gold wallet' . ($fail ? ', ' . $fail . ' failed' : ''))->send();
                    }),
                Tables\Actions\BulkAction::make('bulkSend')
                    ->label('Send selected INVOICE via WhatsApp')->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn () => static::canSend())
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $svc = app(RedeemableQrService::class);
                        [$ok, $fail] = [0, 0];
                        foreach ($records as $r) {
                            if ($r->isDigiWd() || $r->status !== 'pending') {
                                continue;
                            }
                            $svc->deliver($r->source(), withContract: false)['ok'] ? $ok++ : $fail++;
                        }
                        Notification::make()->{$fail ? 'warning' : 'success'}()
                            ->title($ok . ' QR' . ($ok === 1 ? '' : 's') . ' sent' . ($fail ? ', ' . $fail . ' failed' : ''))->send();
                    }),
            ]);
    }
}
