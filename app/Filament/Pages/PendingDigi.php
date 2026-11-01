<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\DigiOrder;
use App\Models\LiveRate;
use App\Services\Digi\PendingDigiService;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Commissions → Pending Digi (board 2026-11-01, night). Every distributor's legacy digi-gold
 * balance (credits minus withdrawals in the old system) waits here as an OPENING row; the
 * purse stays empty until Head Office sends it, per row or in bulk. Nothing is pushed by
 * the import.
 */
class PendingDigi extends Page implements HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use HqOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Pending Digi';

    protected static ?string $title = 'Pending Digi';

    protected static string $view = 'filament.pages.pending-digi';

    public function getSubheading(): ?string
    {
        $p = DigiOrder::where('source', 'OPENING')->where('status', DigiOrder::STATUS_PENDING)->where('gold_wt', '>', 0);
        $grams = (float) (clone $p)->sum('gold_wt');
        $rate = (float) (LiveRate::where('country', 'IN')->latest('effective_at')->value('gold') ?? 0);

        return number_format((clone $p)->count()) . ' distributors waiting · ' . number_format($grams, 4) . ' g (legacy value '
            . Money::inr((float) (clone $p)->sum('value')) . ', today ' . Money::inr($grams * $rate) . ' at ₹' . Money::group($rate) . '/g). '
            . 'Nothing reaches a purse until you send it.';
    }

    public static function canDecide(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(DigiOrder::query()->where('source', 'OPENING')->where('gold_wt', '>', 0)->with(['member.branch', 'decidedBy']))
            ->defaultSort('gold_wt', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')->searchable()->sortable()
                    ->description(fn (DigiOrder $r) => $r->member?->name),
                Tables\Columns\TextColumn::make('member.phone')->label('Phone')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('member.branch.name')->label('Branch')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Last legacy txn')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('gold_wt')->label('Grams')->numeric(4)->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(4)),
                Tables\Columns\TextColumn::make('rate_on_date')->label('Last rate ₹/g')->formatStateUsing(fn ($state) => $state ? Money::inr((float) $state) : '—')->alignEnd(),
                Tables\Columns\TextColumn::make('value')->label('Legacy value ₹')->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->formatStateUsing(fn ($state) => Money::inr((float) $state))),
                Tables\Columns\TextColumn::make('ref_no')->label('Reference')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state === DigiOrder::STATUS_PENDING ? 'Pending' : 'In purse')
                    ->color(fn ($state) => $state === DigiOrder::STATUS_PENDING ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('decided_at')->label('Sent')->dateTime('d M Y H:i')->placeholder('—')->toggleable()
                    ->description(fn (DigiOrder $r) => $r->decidedBy?->name),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([DigiOrder::STATUS_PENDING => 'Pending', DigiOrder::STATUS_CREDITED => 'In purse'])
                    ->default(DigiOrder::STATUS_PENDING)
                    ->selectablePlaceholder(false),
            ])
            ->actions([
                Tables\Actions\Action::make('send')
                    ->label('Send to Digi-gold')->icon('heroicon-o-sparkles')->color('warning')
                    ->visible(fn (DigiOrder $r) => $r->status === DigiOrder::STATUS_PENDING && static::canDecide())
                    ->requiresConfirmation()
                    ->modalHeading('Send this balance to the digi-gold purse?')
                    ->modalDescription(fn (DigiOrder $r) => number_format((float) $r->gold_wt, 4) . ' g (legacy value ' . Money::inr((float) $r->value) . ') goes into the purse of ' . ($r->member?->member_code ?? '—') . ' as one opening line dated ' . $r->created_at?->format('d M Y') . '.')
                    ->action(fn (DigiOrder $r) => $this->run(fn () => app(PendingDigiService::class)->credit($r, auth()->id()))),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkSend')
                    ->label('Send selected to Digi-gold')->icon('heroicon-o-sparkles')->color('warning')
                    ->visible(fn () => static::canDecide())
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->run(function () use ($records) {
                        $svc = app(PendingDigiService::class);
                        $n = 0;
                        foreach ($records as $r) {
                            if ($r->status === DigiOrder::STATUS_PENDING) { $svc->credit($r, auth()->id()); $n++; }
                        }

                        return $n;
                    })),
            ]);
    }

    protected function run(\Closure $fn): void
    {
        try {
            $n = $fn();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();

            return;
        }
        $n = is_int($n) ? $n : 1;
        Notification::make()->success()->title($n . ' balance' . ($n === 1 ? '' : 's') . ' sent to the purse')->send();
    }
}
