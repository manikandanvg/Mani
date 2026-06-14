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
        return $table
            ->query(fn (): Builder => $this->approvalQuery())
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('earned')
                    ->label('Earned on')
                    ->getStateUsing(fn (Model $r) => $this->earnedOn($r))
                    ->date()
                    ->sortable(false),
                TextColumn::make('payee')
                    ->label('Beneficiary (User ID)')
                    ->getStateUsing(fn (Model $r) => $this->payeeLabel($r))
                    ->wrap(),
                TextColumn::make('detail')
                    ->label('Reference')
                    ->getStateUsing(fn (Model $r) => $this->detailLabel($r))
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->getStateUsing(fn (Model $r) => $this->amountOf($r))
                    ->baseMoney(),
                TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (Model $r) => $r->status)
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),
            ])
            ->bulkActions([
                BulkAction::make('approve')
                    ->label('Approve & credit wallet')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Mark the selected commissions as paid and credit each beneficiary\'s wallet. This cannot be undone.')
                    ->action(fn (Collection $records) => $this->approveSelected($records))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('No commissions to approve')
            ->emptyStateDescription('Adjust the type or date range above.');
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

    protected function approveSelected(Collection $records): void
    {
        $svc = app(CommissionApprovalService::class);
        $count = 0;
        $sum = 0.0;
        foreach ($records as $row) {
            if ($svc->approve($row)) {
                $count++;
                $sum += $this->amountOf($row);
            }
        }

        Notification::make()
            ->success()
            ->title($count > 0 ? "Approved {$count} commission(s)" : 'Nothing to approve')
            ->body($count > 0 ? '₹' . number_format($sum, 2) . ' credited to wallets.' : 'Selected rows were already paid.')
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
