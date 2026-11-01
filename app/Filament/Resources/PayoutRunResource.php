<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\PayoutRunResource\Pages;
use App\Filament\Resources\PayoutRunResource\RelationManagers\GapRowsRelationManager;
use App\Models\PayoutRun;
use App\Services\CommissionApprovalService;
use App\Services\Payout\CutoffService;
use App\Support\Money;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Commissions → Payout Runs: the monthly cut-off record (legacy admin/generate/breportmonth).
 * One row per `payout:cutoff` run with its totals, the per-member GAP log, the per-row
 * detail, the text log, and "Approve run" (super admin) = the legacy admin/reports step —
 * cash wallet, net of TDS + service, through the same Commission Approval rules.
 */
class PayoutRunResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = PayoutRun::class;

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Payout Runs';

    protected static ?string $modelLabel = 'Payout Run';

    protected static ?string $pluralModelLabel = 'Payout Runs';

    protected static ?int $navigationSort = 1;

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

    public static function canApprove(PayoutRun $run): bool
    {
        return (bool) auth()->user()?->isSuperAdmin() && $run->status === 'generated';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')->label('Pay month')->date('M Y')->sortable(),
                Tables\Columns\TextColumn::make('cutoff_date')->label('Cut-off')->date('d M Y'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) { 'approved' => 'success', 'generated' => 'warning', default => 'gray' }),
                Tables\Columns\TextColumn::make('gap_bonds')->label('Bonds')->numeric(),
                Tables\Columns\TextColumn::make('gap_rows')->label('GAP rows')->numeric(),
                Tables\Columns\TextColumn::make('gap_members')->label('Members')->numeric(),
                Tables\Columns\TextColumn::make('gap_gross')->label('GAP gross')->formatStateUsing(fn ($state) => Money::inr((float) $state)),
                Tables\Columns\TextColumn::make('cbc_rows')->label('CBC due')->numeric(),
                Tables\Columns\TextColumn::make('cbc_worth')->label('CBC worth (before split)')->formatStateUsing(fn ($state) => Money::inr((float) $state))->toggleable(),
                // Board 2026-11-01 split of the month's coupons (Commission Setup percentages).
                Tables\Columns\TextColumn::make('cbc_cash')->label('CBC → cash wallet')
                    ->state(fn (PayoutRun $r) => Money::inr(CommissionApprovalService::cbcSplit((float) $r->cbc_worth)['cash'])),
                Tables\Columns\TextColumn::make('cbc_withheld')->label('CBC TDS + service')
                    ->state(fn (PayoutRun $r) => Money::inr(CommissionApprovalService::cbcSplit((float) $r->cbc_worth)['tds'] + CommissionApprovalService::cbcSplit((float) $r->cbc_worth)['service']))->toggleable(),
                Tables\Columns\TextColumn::make('cbc_coupon')->label('CBC → coupon wallet')
                    ->state(fn (PayoutRun $r) => Money::inr(CommissionApprovalService::cbcSplit((float) $r->cbc_worth)['coupon']))->toggleable(),
                Tables\Columns\TextColumn::make('runBy.name')->label('Run by')->placeholder('console')->toggleable(),
                Tables\Columns\TextColumn::make('finished_at')->label('Generated')->dateTime('d M Y H:i')->toggleable(),
                Tables\Columns\TextColumn::make('approved_at')->label('Approved')->dateTime('d M Y H:i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['generated' => 'Generated (pending approval)', 'approved' => 'Approved']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::approveAction(),
                static::summaryAction(),
                static::logAction(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Run')->columns(4)->schema([
                Infolists\Components\TextEntry::make('period')->label('Pay month')->date('M Y'),
                Infolists\Components\TextEntry::make('cutoff_date')->label('Bonds up to')->date('d M Y'),
                Infolists\Components\TextEntry::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) { 'approved' => 'success', 'generated' => 'warning', default => 'gray' }),
                Infolists\Components\TextEntry::make('runBy.name')->label('Run by')->placeholder('console'),
                Infolists\Components\TextEntry::make('options')->label('Switches')
                    ->state(fn (PayoutRun $r) => collect($r->options ?? [])->map(fn ($v, $k) => $k . ': ' . ($v ? 'on' : 'off'))->implode(' · ') ?: 'legacy-exact')
                    ->columnSpan(2),
                Infolists\Components\TextEntry::make('finished_at')->label('Generated at')->dateTime('d M Y H:i:s'),
                Infolists\Components\TextEntry::make('approved_at')->label('Approved at')->dateTime('d M Y H:i:s')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Turnover-based salary (GAP) — one row per level per bond, pending until approved')->columns(4)->schema([
                Infolists\Components\TextEntry::make('gap_bonds')->label('Bonds that paid an instalment')->numeric(),
                Infolists\Components\TextEntry::make('gap_rows')->label('Ledger rows')->numeric(),
                Infolists\Components\TextEntry::make('gap_members')->label('Earning members')->numeric(),
                Infolists\Components\TextEntry::make('gap_gross')->label('Gross')->formatStateUsing(fn ($state) => Money::inr((float) $state)),
                Infolists\Components\TextEntry::make('gap_log')->label('GAP log (per member, with TDS & service as Commission Setup would deduct today)')->columnSpanFull()
                    ->state(fn (PayoutRun $r) => new HtmlString(static::summaryHtml($r))),
            ]),
            // Board 2026-11-01 split of every coupon: sales profit → cash wallet, TDS + service withheld,
            // remainder → coupon wallet (HQ reference). Percentages from Commission Setup.
            Infolists\Components\Section::make('Promotional Incentive (CBC) due this month')->columns(3)->schema([
                Infolists\Components\TextEntry::make('cbc_rows')->label('Coupons')->numeric(),
                Infolists\Components\TextEntry::make('cbc_worth')->label('Worth')->formatStateUsing(fn ($state) => Money::inr((float) $state)),
                Infolists\Components\TextEntry::make('cbc_cash')->label(fn (PayoutRun $r) => 'Sales profit → cash wallet (' . rtrim(rtrim(number_format(CommissionApprovalService::cbcSplit(100)['cash_pct'], 2), '0'), '.') . ' %)')
                    ->state(fn (PayoutRun $r) => Money::inr(CommissionApprovalService::cbcSplit((float) $r->cbc_worth)['cash'])),
                Infolists\Components\TextEntry::make('cbc_charges')->label('TDS + service withheld')
                    ->state(fn (PayoutRun $r) => Money::inr(CommissionApprovalService::cbcSplit((float) $r->cbc_worth)['tds'] + CommissionApprovalService::cbcSplit((float) $r->cbc_worth)['service'])),
                Infolists\Components\TextEntry::make('cbc_coupon')->label('Coupon wallet (HQ reference)')
                    ->state(fn (PayoutRun $r) => Money::inr(CommissionApprovalService::cbcSplit((float) $r->cbc_worth)['coupon'])),
            ]),
            Infolists\Components\Section::make('Log')->schema([
                Infolists\Components\TextEntry::make('log_path')->label('File')->placeholder('—')->copyable(),
                Infolists\Components\TextEntry::make('log_tail')->label('Last lines')
                    ->state(fn (PayoutRun $r) => new HtmlString('<pre style="white-space:pre-wrap;font-size:.75rem;max-height:20rem;overflow:auto;background:#f8f7f4;border:1px solid #e7e5e4;border-radius:.5rem;padding:.75rem">' . e(static::logTail($r)) . '</pre>')),
            ])->collapsible(),
        ]);
    }

    public static function getRelations(): array
    {
        return [GapRowsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayoutRuns::route('/'),
            'view' => Pages\ViewPayoutRun::route('/{record}'),
        ];
    }

    // ------------------------------------------------------------------ actions

    public static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approveRun')
            ->label('Approve run')
            ->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (PayoutRun $record) => static::canApprove($record))
            ->requiresConfirmation()
            ->modalHeading(fn (PayoutRun $record) => 'Approve the ' . $record->label() . ' cut-off?')
            ->modalDescription(fn (PayoutRun $record) => 'Credits every pending GAP row (' . number_format($record->gap_rows) . ' rows, gross ' . Money::inr((float) $record->gap_gross) . ') to the members\' CASH wallets net of TDS + service charge, and passes the ' . number_format($record->cbc_rows) . ' CBC coupons due (sales-profit share to the CASH wallet, TDS + service withheld, the rest to the coupon wallet — Commission Setup percentages). Same rules as Commission Approval, for the whole month at once; it cannot be undone.')
            ->action(function (PayoutRun $record) {
                $r = app(CutoffService::class)->approve($record, auth()->id());
                Notification::make()->success()->title('Run ' . $record->label() . ' approved')
                    ->body($r['gap'] . ' GAP rows (gross ' . Money::inr($r['gross']) . ') for ' . $r['members'] . ' members and ' . $r['cbc'] . ' CBC coupons credited.')->send();
            });
    }

    public static function summaryAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('memberSummary')
            ->label('GAP log (xlsx)')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->action(fn (PayoutRun $record) => static::summaryDownload($record));
    }

    /** The per-member GAP log as an xlsx download (legacy tbl_gap_log shape). */
    public static function summaryDownload(PayoutRun $record)
    {
        $path = storage_path('app/payouts/gap-log_' . $record->period->format('Y-m') . '_run' . $record->id . '.xlsx');
        @mkdir(dirname($path), 0775, true);
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Pay month', 'Member', 'Name', 'Rows', 'Gross', 'TDS', 'Service charge', 'Net payable', 'Status']));
        foreach ($record->memberSummary() as $s) {
            $writer->addRow(Row::fromValues([$record->label(), $s['member_code'], $s['name'], $s['rows'], $s['gross'], $s['tds'], $s['service'], $s['net'], $s['status']]));
        }
        $writer->close();

        return response()->download($path)->deleteFileAfterSend();
    }

    public static function logAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('downloadLog')
            ->label('Log')->icon('heroicon-o-document-text')->color('gray')
            ->visible(fn (PayoutRun $record) => $record->log_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($record->log_path))
            ->action(fn (PayoutRun $record) => response()->download(\Illuminate\Support\Facades\Storage::disk('local')->path($record->log_path)));
    }

    // ------------------------------------------------------------------ helpers

    public static function summaryHtml(PayoutRun $run): string
    {
        $rows = $run->memberSummary();
        if (! $rows) {
            return '<p class="text-sm text-gray-500">No GAP rows in this run.</p>';
        }
        $t = ['rows' => 0, 'gross' => 0.0, 'tds' => 0.0, 'service' => 0.0, 'net' => 0.0];
        $html = '<div style="max-height:28rem;overflow:auto"><table class="w-full text-sm" style="border-collapse:collapse"><thead><tr style="text-align:left;border-bottom:1px solid #e7e5e4">'
            . '<th style="padding:.3rem">Member</th><th style="padding:.3rem">Name</th><th style="padding:.3rem;text-align:right">Rows</th><th style="padding:.3rem;text-align:right">Gross</th><th style="padding:.3rem;text-align:right">TDS</th><th style="padding:.3rem;text-align:right">Service</th><th style="padding:.3rem;text-align:right">Net</th><th style="padding:.3rem">Status</th></tr></thead><tbody>';
        foreach ($rows as $s) {
            foreach (['rows', 'gross', 'tds', 'service', 'net'] as $k) {
                $t[$k] += $s[$k];
            }
            $html .= '<tr style="border-bottom:1px solid #f1efe9"><td style="padding:.3rem">' . e($s['member_code']) . '</td><td style="padding:.3rem">' . e($s['name']) . '</td>'
                . '<td style="padding:.3rem;text-align:right">' . $s['rows'] . '</td><td style="padding:.3rem;text-align:right">' . e(Money::group($s['gross'])) . '</td>'
                . '<td style="padding:.3rem;text-align:right">' . e(Money::group($s['tds'])) . '</td><td style="padding:.3rem;text-align:right">' . e(Money::group($s['service'])) . '</td>'
                . '<td style="padding:.3rem;text-align:right;font-weight:600">' . e(Money::group($s['net'])) . '</td><td style="padding:.3rem">' . e($s['status']) . '</td></tr>';
        }
        $html .= '</tbody><tfoot><tr style="font-weight:700;border-top:2px solid #e7e5e4"><td style="padding:.3rem" colspan="2">' . count($rows) . ' members</td>'
            . '<td style="padding:.3rem;text-align:right">' . $t['rows'] . '</td><td style="padding:.3rem;text-align:right">' . e(Money::group($t['gross'])) . '</td>'
            . '<td style="padding:.3rem;text-align:right">' . e(Money::group($t['tds'])) . '</td><td style="padding:.3rem;text-align:right">' . e(Money::group($t['service'])) . '</td>'
            . '<td style="padding:.3rem;text-align:right">' . e(Money::group($t['net'])) . '</td><td></td></tr></tfoot></table></div>';

        return $html;
    }

    public static function logTail(PayoutRun $run, int $lines = 60): string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $run->log_path || ! $disk->exists($run->log_path)) {
            return '(no log file)';
        }
        $all = preg_split('/\r?\n/', (string) $disk->get($run->log_path));

        return implode("\n", array_slice($all, -$lines));
    }
}
