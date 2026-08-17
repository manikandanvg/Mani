<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\ResellerCommission;
use App\Services\CommissionApprovalService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Commission Approval — one page to approve all 7 earning streams. Pick a type + date
 * range, tick the rows (or select-all), and Approve: each record is marked paid with a
 * paid date and its amount is posted to the beneficiary's wallet (the single gate —
 * CommissionApprovalService). HQ-only.
 */
class CommissionApproval extends Page implements HasForms, HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use HqOnly;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?int $navigationSort = 0;   // the action page sits above the ledgers

    protected static ?string $navigationLabel = 'Commission Approval';

    protected static ?string $title = 'Commission Approval';

    protected static string $view = 'filament.pages.commission-approval';

    /** Filter bar state (type + earned date range). */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'type' => 'IC',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()->columns(3)->schema([
                    Select::make('type')
                        ->label('Commission type')
                        ->options(CommissionApprovalService::TYPES)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn () => $this->deselectAllTableRecords()),
                    DatePicker::make('from')->label('Earned from')->live()
                        ->afterStateUpdated(fn () => $this->deselectAllTableRecords()),
                    DatePicker::make('to')->label('Earned to')->live()
                        ->afterStateUpdated(fn () => $this->deselectAllTableRecords()),
                ]),
            ])
            ->statePath('data');
    }

    public function table(Table $table): Table
    {
        // Board 2026-08-10: ACCUMULATED view — one row per beneficiary with the entry
        // count and summed value; "View entries" drills into the underlying rows.
        // Approving a beneficiary row settles EVERY one of their entries in the
        // filtered window (each still individually TDS-stamped by the service).
        return $table
            ->query(fn (): Builder => $this->aggregatedQuery())
            // grouped query: Filament's implicit ORDER BY primary key violates
            // only_full_group_by — sort by the aggregate instead, biggest first
            ->defaultSort('total_amount', 'desc')
            ->defaultKeySort(false)
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('payee')
                    ->label('Beneficiary (User ID)')
                    ->getStateUsing(fn (Model $r) => $this->payeeLabel($r))
                    ->wrap(),
                TextColumn::make('entries_count')
                    ->label('Entries')
                    ->alignCenter(),
                TextColumn::make('period')
                    ->label('Earned between')
                    ->getStateUsing(fn (Model $r) => $r->first_earned === $r->last_earned
                        ? \Illuminate\Support\Carbon::parse($r->first_earned)->format('d M Y')
                        : \Illuminate\Support\Carbon::parse($r->first_earned)->format('d M Y')
                            . ' – ' . \Illuminate\Support\Carbon::parse($r->last_earned)->format('d M Y')),
                TextColumn::make('total_amount')
                    ->label('Total amount')
                    ->formatStateUsing(fn ($state) => '₹ ' . \App\Support\Money::group((float) $state))
                    ->sortable(),
            ])
            ->actions([
                \Filament\Tables\Actions\Action::make('entries')
                    ->label('View entries')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Model $r) => $this->payeeLabel($r))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Model $r) => new \Illuminate\Support\HtmlString($this->entriesHtml($r))),
            ])
            ->bulkActions([
                BulkAction::make('approve')
                    ->label('Approve & credit wallet')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Approve ALL listed entries of the selected beneficiaries and credit their wallets. This cannot be undone.')
                    ->action(fn (Collection $records) => $this->approveSelected($records))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('No commissions to approve')
            ->emptyStateDescription('Adjust the type or date range above.');
    }

    /** Grouping key per stream: members earn IC/GAP/CBC; branches earn the margins. */
    protected function groupColumn(): string
    {
        $type = $this->data['type'] ?? 'IC';

        return in_array($type, ['BILL_MARGIN', 'GOLD_MARGIN', 'SILVER_MARGIN', 'STOCK_TRANSFER_MARGIN', 'RD_RENEWAL_MARGIN'], true)
            ? 'branch_id'
            : 'member_id';
    }

    /** The money column of the selected stream's source table. */
    protected function amountColumn(): string
    {
        return match ($this->data['type'] ?? 'IC') {
            'CBC' => 'worth',
            'BILL_MARGIN', 'GOLD_MARGIN', 'SILVER_MARGIN', 'STOCK_TRANSFER_MARGIN', 'RD_RENEWAL_MARGIN' => 'com_value',
            default => 'amount',
        };
    }

    /** The source query for the currently selected type + date range. */
    protected function approvalQuery(): Builder
    {
        $svc = app(CommissionApprovalService::class);

        return $svc->query(
            $this->data['type'] ?? 'IC',
            $this->data['from'] ?? null,
            $this->data['to'] ?? null,
        );
    }

    /** One row per beneficiary: entry count, summed value, earned window. */
    protected function aggregatedQuery(): Builder
    {
        $key = $this->groupColumn();
        $amount = $this->amountColumn();
        $date = $this->dateColumn();

        return $this->approvalQuery()
            ->reorder()
            ->selectRaw("MIN(id) AS id, {$key}, COUNT(*) AS entries_count, SUM({$amount}) AS total_amount, MIN({$date}) AS first_earned, MAX({$date}) AS last_earned")
            ->groupBy($key);
    }

    /** The date column of the selected stream's source table. */
    protected function dateColumn(): string
    {
        return match ($this->data['type'] ?? 'IC') {
            'CBC' => 'cbc_date',
            'BILL_MARGIN', 'GOLD_MARGIN', 'SILVER_MARGIN', 'STOCK_TRANSFER_MARGIN', 'RD_RENEWAL_MARGIN' => 'bill_date',
            default => 'earned_on',
        };
    }

    /** The drill-down list behind an aggregated row. */
    protected function underlyingRows(Model $aggregate): Collection
    {
        $key = $this->groupColumn();

        return $this->approvalQuery()->where($key, $aggregate->{$key})->get();
    }

    protected function entriesHtml(Model $aggregate): string
    {
        $html = '<table style="width:100%;font-size:.85rem;border-collapse:collapse">'
            . '<tr style="text-align:left"><th style="padding:.35rem .5rem">Earned on</th>'
            . '<th style="padding:.35rem .5rem">Reference</th>'
            . '<th style="padding:.35rem .5rem;text-align:right">Amount</th></tr>';
        $total = 0.0;
        foreach ($this->underlyingRows($aggregate) as $row) {
            $amount = $this->amountOf($row);
            $total += $amount;
            $earned = $this->earnedOn($row);
            $html .= '<tr style="border-top:1px solid #eee">'
                . '<td style="padding:.35rem .5rem">' . e($earned ? \Illuminate\Support\Carbon::parse($earned)->format('d M Y') : '—') . '</td>'
                . '<td style="padding:.35rem .5rem">' . e($this->detailLabel($row)) . '</td>'
                . '<td style="padding:.35rem .5rem;text-align:right">₹ ' . \App\Support\Money::group($amount) . '</td></tr>';
        }
        $html .= '<tr style="border-top:2px solid #ddd;font-weight:700"><td colspan="2" style="padding:.35rem .5rem">Total</td>'
            . '<td style="padding:.35rem .5rem;text-align:right">₹ ' . \App\Support\Money::group($total) . '</td></tr></table>';

        return $html;
    }

    /** Approve every underlying entry of each selected beneficiary row. */
    protected function approveSelected(Collection $records): void
    {
        $svc = app(CommissionApprovalService::class);
        $count = 0;
        $sum = 0.0;
        $beneficiaries = 0;
        foreach ($records as $aggregate) {
            $beneficiaries++;
            $memberSum = 0.0;
            $memberCount = 0;
            foreach ($this->underlyingRows($aggregate) as $row) {
                if ($svc->approve($row)) {
                    $count++;
                    $memberCount++;
                    $sum += $this->amountOf($row);
                    $memberSum += $this->amountOf($row);
                }
            }

            // Commission-distribution acknowledgement (board 2026-08-11: push + inbox).
            if ($memberCount > 0) {
                $member = $this->groupColumn() === 'member_id'
                    ? \App\Models\Member::find($aggregate->member_id)
                    : \App\Models\Branch::find($aggregate->branch_id)?->distributorUser?->memberAccount;
                \App\Services\Push\Notifier::to($member, 'commission',
                    'Commission credited to your wallet',
                    "{$memberCount} " . ($this->data['type'] ?? 'commission') . ' entr' . ($memberCount === 1 ? 'y' : 'ies')
                        . ' approved — gross ₹' . \App\Support\Money::group($memberSum) . '; the net after TDS & service charge is now in your wallet.',
                    route: '/wallet',
                );
            }
        }

        Notification::make()
            ->success()
            ->title($count > 0 ? "Approved {$count} commission(s) for {$beneficiaries} beneficiar" . ($beneficiaries === 1 ? 'y' : 'ies') : 'Nothing to approve')
            ->body($count > 0 ? \App\Support\Money::inr($sum) . ' credited to wallets.' : 'Selected rows were already paid.')
            ->send();
    }

    // --- per-model normalisers (rows are homogeneous within one render) ---

    protected function earnedOn(Model $r)
    {
        return match (true) {
            $r instanceof CommissionLedger => $r->earned_on,
            $r instanceof CbcEntry => $r->cbc_date,
            $r instanceof ResellerCommission => $r->bill_date,
            default => null,
        };
    }

    protected function amountOf(Model $r): float
    {
        return match (true) {
            $r instanceof CommissionLedger => (float) $r->amount,
            $r instanceof CbcEntry => (float) $r->worth,
            $r instanceof ResellerCommission => (float) $r->com_value,
            default => 0.0,
        };
    }

    protected function payeeLabel(Model $r): string
    {
        if ($r instanceof CommissionLedger || $r instanceof CbcEntry) {
            $m = $r->member;

            return $m ? trim(($m->member_code ?? '') . ' — ' . ($m->name ?? '')) : '—';
        }
        if ($r instanceof ResellerCommission) {
            $branch = $r->branch;
            $dist = $branch?->distributorUser?->memberAccount;
            $payee = $dist ? ($dist->member_code . ' — ' . $dist->name) : 'branch balance';

            return ($branch?->name ?? 'Branch') . ' (' . $payee . ')';
        }

        return '—';
    }

    protected function detailLabel(Model $r): string
    {
        if ($r instanceof CommissionLedger) {
            return trim(($r->invoice_no ? "Inv {$r->invoice_no}" : '') . ($r->level ? " · L{$r->level}" : ''))
                ?: ('From #' . ($r->from_member_id ?? '—'));
        }
        if ($r instanceof CbcEntry) {
            return $r->code . ($r->bond_id ? " · Bond #{$r->bond_id}" : '');
        }
        if ($r instanceof ResellerCommission) {
            return $r->invoice_no ? "Inv {$r->invoice_no}" : ('Txn #' . $r->id);
        }

        return '—';
    }
}
