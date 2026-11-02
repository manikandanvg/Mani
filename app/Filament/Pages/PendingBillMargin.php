<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\PendingBillMargin as Row;
use App\Services\Commission\PendingBillMarginService;
use App\Support\Money;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Commissions → Pending Billing Margin (user 2026-11-02). Every dealer's legacy accrued
 * billing margin (the unpaid balance it saw in legacy) waits here, one row per branch.
 * Nothing is credited automatically: Head Office sends it to the dealer's cash wallet per
 * row or in bulk, or rejects it with a remark — the Pending Cash QR pattern.
 */
class PendingBillMargin extends Page implements HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use HqOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Pending Billing Margin';

    protected static ?string $title = 'Pending Billing Margin';

    protected static string $view = 'filament.pages.pending-bill-margin';

    public function getSubheading(): ?string
    {
        $p = Row::where('status', Row::STATUS_PENDING);

        return number_format((clone $p)->count()) . ' branches waiting · ' . Money::inr((float) (clone $p)->sum('amount'))
            . ' legacy billing margin. Sent to the cash wallet of each branch\'s distributor login, per row or in bulk — never automatically.';
    }

    public static function canDecide(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Row::query()->with(['branch.distributorUser.memberAccount', 'member', 'decidedBy']))
            ->defaultSort('amount', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable()->sortable()
                    ->description(fn (Row $r) => implode(' / ', array_filter([$r->branch?->city, $r->branch?->phone, $r->branch?->incharge]))),
                Tables\Columns\TextColumn::make('branch.legacy_brid')->label('Legacy id')->sortable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('branch.level')->label('Level')->badge()->formatStateUsing(fn ($state) => $state ? str($state)->replace('_', ' ')->title() : '—')->toggleable(),
                Tables\Columns\TextColumn::make('branch.distributorUser.email')->label('Login')->searchable()->toggleable()->placeholder('— no login —'),
                Tables\Columns\TextColumn::make('branch.distributorUser.memberAccount.member_code')->label('Pays to')->placeholder('— no member account —')
                    ->description(fn (Row $r) => $r->branch?->distributorUser?->memberAccount?->name),
                Tables\Columns\TextColumn::make('amount')->label('Margin ₹')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->formatStateUsing(fn ($state) => Money::inr((float) $state))),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) { Row::STATUS_SENT => 'success', Row::STATUS_REJECTED => 'danger', default => 'warning' }),
                Tables\Columns\TextColumn::make('decided_at')->label('Decided')->dateTime('d M Y H:i')->placeholder('—')->toggleable()
                    ->description(fn (Row $r) => trim(($r->decidedBy?->name ?? '') . ($r->remarks ? ' — ' . $r->remarks : ''))),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([Row::STATUS_PENDING => 'Pending', Row::STATUS_SENT => 'Sent', Row::STATUS_REJECTED => 'Rejected'])
                    ->default(Row::STATUS_PENDING)
                    ->selectablePlaceholder(false),
            ])
            ->actions([
                Tables\Actions\Action::make('send')
                    ->label('Send to wallet')->icon('heroicon-o-wallet')->color('success')
                    ->visible(fn (Row $r) => $r->status === Row::STATUS_PENDING && static::canDecide())
                    ->requiresConfirmation()
                    ->modalHeading('Send this billing margin to the cash wallet?')
                    ->modalDescription(fn (Row $r) => Money::inr((float) $r->amount) . ' is credited to ' . ($r->payeeMember()?->member_code ?? '— no member account —')
                        . ' (' . ($r->branch?->pickLabel() ?? '') . '). No TDS or service charge is withheld on a legacy balance.')
                    ->action(fn (Row $r) => $this->run(fn () => app(PendingBillMarginService::class)->send($r, auth()->id()), 'sent')),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Row $r) => $r->status === Row::STATUS_PENDING && static::canDecide())
                    ->form([Forms\Components\Textarea::make('remarks')->label('Why')->required()->maxLength(255)])
                    ->action(fn (Row $r, array $data) => $this->run(fn () => app(PendingBillMarginService::class)->reject($r, auth()->id(), $data['remarks'] ?? null), 'rejected')),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkSend')
                    ->label('Send selected to cash wallet')->icon('heroicon-o-wallet')->color('success')
                    ->visible(fn () => static::canDecide())
                    ->requiresConfirmation()
                    ->modalDescription('Each selected branch\'s legacy billing margin is credited to its distributor login\'s cash wallet. Branches without a member account are skipped and reported.')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->run(function () use ($records) {
                        $svc = app(PendingBillMarginService::class);
                        [$n, $skipped] = [0, []];
                        foreach ($records as $r) {
                            if ($r->status !== Row::STATUS_PENDING) {
                                continue;
                            }
                            try {
                                $svc->send($r, auth()->id());
                                $n++;
                            } catch (\Throwable $e) {
                                $skipped[] = ($r->branch?->name ?? '#' . $r->branch_id) . ': ' . $e->getMessage();
                            }
                        }
                        if ($skipped) {
                            Notification::make()->warning()->title(count($skipped) . ' skipped')->body(implode("\n", array_slice($skipped, 0, 8)))->persistent()->send();
                        }

                        return $n;
                    }, 'sent')),
            ]);
    }

    protected function run(\Closure $fn, string $verb): void
    {
        try {
            $n = $fn();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Not ' . $verb)->body($e->getMessage())->send();

            return;
        }
        $n = is_int($n) ? $n : 1;
        Notification::make()->success()->title($n . ' margin' . ($n === 1 ? '' : 's') . ' ' . $verb)->send();
    }
}
