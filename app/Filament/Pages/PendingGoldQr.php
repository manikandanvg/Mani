<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\RedeemableQr;
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
 * Commissions → Pending Gold QR (board 2026-11-01). Every legacy invoice QR (gold / silver)
 * still awaiting delivery, carried over as a redeemable QR with the metal price of its own
 * date. Head Office sends it to the distributor over WhatsApp (single or bulk); the
 * distributor redeems it at ANY branch, priced at that date's rate (Redeem QR screen).
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

        return number_format((clone $gold)->count()) . ' gold QRs (' . number_format((float) (clone $gold)->sum('gram_worth'), 3) . ' g, ' . Money::inr((float) (clone $gold)->sum('cash_worth')) . ') · '
            . number_format((clone $silver)->count()) . ' silver QRs (' . number_format((float) (clone $silver)->sum('gram_worth'), 3) . ' g, ' . Money::inr((float) (clone $silver)->sum('cash_worth')) . ') waiting. '
            . 'Each redeems at any branch at the metal price of its own date.';
    }

    public static function canSend(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(RedeemableQr::query()->where('source', RedeemableQr::SOURCE_LEGACY)->with(['member', 'bond.plan', 'branch', 'redeemBranch']))
            ->defaultSort('priced_on', 'asc')
            ->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('priced_on')->label('QR date (price date)')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')->searchable()->sortable()
                    ->description(fn (RedeemableQr $r) => $r->member?->name),
                Tables\Columns\TextColumn::make('member.phone')->label('Phone')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('bond.plan.code')->label('Plan')->placeholder('contract deleted in legacy')->toggleable(),
                Tables\Columns\TextColumn::make('invoice_no')->label('Invoice')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Billing branch')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('qr_code')->label('QR code')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('qr_mode')->label('Metal')->badge()
                    ->color(fn ($state) => $state === 'silver' ? 'gray' : 'warning'),
                Tables\Columns\TextColumn::make('gram_worth')->label('Grams')->numeric(3)->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(3)),
                Tables\Columns\TextColumn::make('frozen_rate')->label('Rate ₹/g (that date)')->formatStateUsing(fn ($state) => $state ? Money::inr((float) $state) : '—')->alignEnd(),
                Tables\Columns\TextColumn::make('cash_worth')->label('Worth ₹')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->formatStateUsing(fn ($state) => Money::inr((float) $state))),
                Tables\Columns\IconColumn::make('qr_sent')->label('Sent')->boolean(),
                Tables\Columns\TextColumn::make('sent_at')->label('Sent at')->dateTime('d M Y H:i')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'pending' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('redeemBranch.name')->label('Redeemed at')->placeholder('—')->toggleable()
                    ->description(fn (RedeemableQr $r) => $r->redeemed_at?->format('d M Y')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'redeemed' => 'Redeemed'])
                    ->default('pending')
                    ->selectablePlaceholder(false),
                Tables\Filters\SelectFilter::make('qr_mode')->label('Metal')->options(['gold' => 'Gold', 'silver' => 'Silver']),
                Tables\Filters\TernaryFilter::make('qr_sent')->label('Sent to distributor'),
            ])
            ->actions([
                Tables\Actions\Action::make('send')
                    ->label(fn (RedeemableQr $r) => $r->qr_sent ? 'Resend' : 'Send via WhatsApp')
                    ->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn (RedeemableQr $r) => $r->status === 'pending' && static::canSend())
                    ->requiresConfirmation()
                    ->modalDescription(fn (RedeemableQr $r) => 'The QR image and the redeem message go to ' . ($r->member?->phone ?? 'the distributor') . ' on WhatsApp (and to the app inbox).')
                    ->action(function (RedeemableQr $r) {
                        $res = app(RedeemableQrService::class)->deliver($r, withContract: false);
                        $res['ok']
                            ? Notification::make()->success()->title('QR ' . $r->qr_code . ' sent')->body($res['message'] ?? '')->send()
                            : Notification::make()->danger()->title('QR ' . $r->qr_code . ' not sent')->body($res['message'] ?? '')->send();
                    }),
                Tables\Actions\Action::make('image')
                    ->label('QR image')->icon('heroicon-o-photo')->color('gray')
                    ->url(fn (RedeemableQr $r) => app(RedeemableQrService::class)->imageUrl($r), shouldOpenInNewTab: true),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkSend')
                    ->label('Send selected via WhatsApp')->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn () => static::canSend())
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $svc = app(RedeemableQrService::class);
                        [$ok, $fail] = [0, 0];
                        foreach ($records as $r) {
                            if ($r->status !== 'pending') {
                                continue;
                            }
                            $svc->deliver($r, withContract: false)['ok'] ? $ok++ : $fail++;
                        }
                        Notification::make()->{$fail ? 'warning' : 'success'}()
                            ->title($ok . ' QR' . ($ok === 1 ? '' : 's') . ' sent' . ($fail ? ', ' . $fail . ' failed' : ''))->send();
                    }),
            ]);
    }
}
