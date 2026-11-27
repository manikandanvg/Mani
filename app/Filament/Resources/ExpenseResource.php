<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\ExpenseResource\Pages;
use App\Filament\Support\CommonFilters;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Rank;
use App\Support\Money;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Expense register (board 2026-11-17, point 5): Head Office submits every expense it pays
 * outside the system's own payouts — TBP awards (car, tour …), office rent, salaries outside
 * payroll, electricity … — with the bill attached. The owner approves; approved rows feed the
 * auditor's Balance Sheet / Profit & Loss. An approved expense is never edited or deleted.
 */
class ExpenseResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = Expense::class;

    protected static ?string $navigationGroup = 'Report';

    protected static ?int $navigationSort = -5;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Expenses';

    protected static ?string $modelLabel = 'Expense';

    protected static ?string $pluralModelLabel = 'Expenses';

    public static function canEdit($record): bool
    {
        return static::hqOnlyAllowed() && $record->status !== Expense::APPROVED;
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->isSuperAdmin() && $record->status !== Expense::APPROVED;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canApprove(Expense $record): bool
    {
        return (bool) auth()->user()?->isSuperAdmin() && $record->status === Expense::SUBMITTED;
    }

    /**
     * Undo approval (user 2026-11-27, a safeguard): the owner alone can take an approved row
     * back to Submitted. It then leaves the auditor reports and can be corrected, re-approved or
     * deleted. A reason is required; who, when and why stay on the row and in the Activity Log.
     */
    public static function canUndoApproval(Expense $record): bool
    {
        return (bool) auth()->user()?->isSuperAdmin() && $record->status === Expense::APPROVED;
    }

    public static function undoApproval(Expense $record, string $reason): void
    {
        $record->update([
            'status' => Expense::SUBMITTED,
            'approved_by' => null,
            'approved_at' => null,
            'reject_reason' => mb_substr('Approval undone by ' . (auth()->user()?->name ?? 'system') . ' on ' . now()->format('d M Y, h:i A') . ' — ' . trim($reason), 0, 255),
        ]);
    }

    /**
     * The ledger folded to one line per month per head (a TBP award per stage), with a total per
     * month and the grand totals — the row-by-row list is too long for a PDF.
     *
     * @return array{rows: array<int, array<int, string>>, count: int, expenses: float, assets: float, approved: float, waiting: float}
     */
    public static function summary(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $records = $query->clone()->reorder()->toBase()->get(['expense_date', 'category', 'rank_id', 'amount', 'status']);
        $stages = \App\Models\Rank::all()->mapWithKeys(fn ($r) => [$r->id => \App\Support\Translatable::pick($r->name) ?: $r->code]);

        $months = [];
        $out = ['rows' => [], 'count' => $records->count(), 'expenses' => 0.0, 'assets' => 0.0, 'approved' => 0.0, 'waiting' => 0.0];
        foreach ($records as $r) {
            $asset = isset(Expense::ASSET_CATEGORIES[$r->category]);
            $head = Expense::categoryLabel($r->category) . ($r->rank_id ? ' — ' . ($stages[$r->rank_id] ?? '') : '');
            $key = ($asset ? '1' : '0') . '|' . $r->category . '|' . str_pad((string) (int) $r->rank_id, 6, '0', STR_PAD_LEFT);
            $ym = substr((string) $r->expense_date, 0, 7);
            $months[$ym][$key] ??= ['kind' => $asset ? 'Capital asset' : 'Expense', 'head' => $head, 'n' => 0, 'sum' => 0.0];
            $months[$ym][$key]['n']++;
            $months[$ym][$key]['sum'] += (float) $r->amount;
            $out[$asset ? 'assets' : 'expenses'] += (float) $r->amount;
            if ($r->status === Expense::APPROVED) {
                $out['approved'] += (float) $r->amount;
            } elseif ($r->status === Expense::SUBMITTED) {
                $out['waiting'] += (float) $r->amount;
            }
        }
        ksort($months);

        foreach ($months as $ym => $heads) {
            ksort($heads);
            $label = \Illuminate\Support\Carbon::parse($ym . '-01')->format('M Y');
            $n = 0;
            $sum = 0.0;
            foreach ($heads as $h) {
                $out['rows'][] = [$label, $h['kind'], $h['head'], (string) $h['n'], Money::group($h['sum'])];
                $n += $h['n'];
                $sum += $h['sum'];
            }
            $out['rows'][] = ['', '', 'TOTAL — ' . $label, (string) $n, Money::group($sum)];
        }
        if ($out['rows'] !== []) {
            $out['rows'][] = ['', '', 'TOTAL EXPENSES', '', Money::group($out['expenses'])];
            $out['rows'][] = ['', '', 'TOTAL CAPITAL ASSETS', '', Money::group($out['assets'])];
            $out['rows'][] = ['', '', 'GRAND TOTAL', (string) $out['count'], Money::group($out['expenses'] + $out['assets'])];
        }

        return $out;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Expense::where('status', Expense::SUBMITTED)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for approval';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Expense')->columns(3)->schema([
                Forms\Components\DatePicker::make('expense_date')->label('Paid on')
                    ->required()->native(false)->default(fn () => now()->toDateString())->maxDate(now()),
                Forms\Components\Select::make('category')
                    ->options(Expense::groupedCategoryOptions())->required()->live()->native(false)
                    ->helperText(fn (Forms\Get $get) => isset(Expense::ASSET_CATEGORIES[$get('category')])
                        ? 'Capital asset: shown on the Balance Sheet under Fixed Assets, not in the Profit & Loss.' : null),
                Forms\Components\TextInput::make('amount')->label('Amount (₹)')
                    ->required()->numeric()->minValue(0.01)->step('0.01'),

                // --- TBP award / office rent: who it is for -------------------------------
                Forms\Components\Select::make('member_id')
                    ->label('Distributor')
                    ->helperText('The distributor who received the award, or whose office the rent is for.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Member::query()
                        ->where(fn ($q) => $q->where('member_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"))
                        ->orderBy('name')->limit(30)->get()
                        ->mapWithKeys(fn (Member $m) => [$m->id => "{$m->name} ({$m->member_code})"])->all())
                    ->getOptionLabelUsing(fn ($value) => ($m = Member::find($value)) ? "{$m->name} ({$m->member_code})" : null)
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $m = $state ? Member::with('rank')->find($state) : null;
                        if (! $m) {
                            return;
                        }
                        if (blank($get('payee_name'))) {
                            $set('payee_name', $m->name);
                        }
                        if (blank($get('rank_id')) && $m->rank && $m->rank->depth > 0) {
                            $set('rank_id', $m->rank_id);
                            static::fillFromStage($m->rank, $get, $set);
                        }
                    })
                    ->visible(fn (Forms\Get $get) => in_array($get('category'), ['tbp_reward', 'office_rent'], true))
                    ->required(fn (Forms\Get $get) => $get('category') === 'tbp_reward'),
                Forms\Components\Select::make('rank_id')
                    ->label('TBP Stage')
                    ->helperText('Fills the award and its value (or the monthly rent) set on the TBP Stages page.')
                    ->options(fn () => Rank::where('depth', '>', 0)->orderBy('depth')->get()
                        ->mapWithKeys(fn (Rank $r) => [$r->id => Translatable::pick($r->name) ?: $r->code])->all())
                    ->live()
                    ->afterStateUpdated(fn ($state, Forms\Get $get, Forms\Set $set) => static::fillFromStage($state ? Rank::find($state) : null, $get, $set))
                    ->visible(fn (Forms\Get $get) => in_array($get('category'), ['tbp_reward', 'office_rent'], true)),
                Forms\Components\TextInput::make('period_month')
                    ->label('For the month')->type('month')
                    ->visible(fn (Forms\Get $get) => in_array($get('category'), ['office_rent', 'salary', 'electricity', 'meeting', 'food', 'travel', 'fuel'], true))
                    ->required(fn (Forms\Get $get) => $get('category') === 'office_rent'),

                Forms\Components\TextInput::make('title')
                    ->label('Paid for')->placeholder('e.g. Car award — Zonal Admin, Office rent March, EB bill')
                    ->required()->maxLength(200)->columnSpan(2),
                CommonFilters::branchSelect(Forms\Components\Select::make('branch_id')->label('Branch / office')),
            ]),

            Forms\Components\Section::make('Payment')->columns(3)->schema([
                Forms\Components\TextInput::make('payee_name')->label('Paid to')->maxLength(200),
                Forms\Components\Select::make('payment_mode')->label('Mode')
                    ->options(Expense::MODES)->default('bank')->required()->native(false),
                Forms\Components\TextInput::make('payment_reference')->label('UTR / cheque / bill no')->maxLength(100),
                Forms\Components\FileUpload::make('attachments')
                    ->label('Bill / receipt')
                    ->multiple()->maxFiles(5)->maxSize(8192)->appendFiles()
                    ->disk('local')->directory('expense-bills')->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->downloadable()
                    ->columnSpan(2),
                Forms\Components\Textarea::make('remarks')->rows(3)->maxLength(1000),
            ]),
        ]);
    }

    /** Award text + value (TBP reward) or the monthly rent (office rent) of the chosen stage. */
    protected static function fillFromStage(?Rank $rank, Forms\Get $get, Forms\Set $set): void
    {
        if (! $rank) {
            return;
        }
        $stage = Translatable::pick($rank->name) ?: $rank->code;
        if ($get('category') === 'tbp_reward') {
            if (blank($get('title'))) {
                $set('title', trim((Translatable::pick($rank->reward_title) ?: 'TBP award') . ' — ' . $stage));
            }
            if (blank($get('amount')) && (float) $rank->reward_amount > 0) {
                $set('amount', (float) $rank->reward_amount);
            }
        }
        if ($get('category') === 'office_rent') {
            if (blank($get('title'))) {
                $set('title', 'Office rent — ' . $stage);
            }
            if (blank($get('amount')) && (float) $rank->monthly_rent > 0) {
                $set('amount', (float) $rank->monthly_rent);
            }
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['member', 'rank', 'branch', 'submitter', 'approver']))
            ->defaultSort('expense_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('expense_no')->label('Expense no')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('expense_date')->label('Paid on')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('category')->badge()
                    ->formatStateUsing(fn (?string $state) => Expense::categoryLabel($state))
                    ->color(fn (?string $state) => match ($state) {
                        'tbp_reward' => 'warning', 'office_rent' => 'info',
                        default => isset(Expense::ASSET_CATEGORIES[$state]) ? 'success' : 'gray',
                    })
                    ->description(fn (Expense $r) => $r->isAsset() ? 'Capital asset' : null),
                Tables\Columns\TextColumn::make('title')->label('Paid for')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')
                    ->formatStateUsing(fn ($state, Expense $r) => $r->member ? "{$r->member->name} ({$r->member->member_code})" : '—')
                    ->description(fn (Expense $r) => $r->rank ? (Translatable::pick($r->rank->name) ?: $r->rank->code) : null)
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('member', fn (Builder $m) => $m
                        ->where('member_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('payee_name')->label('Paid to')->searchable()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch / office')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('period_month')->label('For month')
                    ->formatStateUsing(fn (?string $state) => $state ? \Illuminate\Support\Carbon::parse($state . '-01')->format('M Y') : null)
                    ->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($state) => Money::inr((float) $state))->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')
                        ->formatStateUsing(fn ($state) => Money::inr((float) $state))),
                Tables\Columns\TextColumn::make('payment_mode')->label('Mode')
                    ->formatStateUsing(fn (?string $state) => Expense::MODES[$state] ?? ucfirst((string) $state))
                    ->description(fn (Expense $r) => $r->payment_reference)->toggleable(),
                Tables\Columns\TextColumn::make('bills')->label('Bill')
                    ->state(fn (Expense $r) => count((array) $r->attachments))
                    ->formatStateUsing(fn ($state, Expense $r) => new HtmlString(collect((array) $r->attachments)->keys()
                        ->map(fn ($i) => '<a href="' . e(route('expense.attachment', ['expense' => $r->id, 'index' => $i])) . '" target="_blank" rel="noopener"'
                            . ' style="color:#ab222f;font-weight:600;text-decoration:underline">Bill ' . ($i + 1) . '</a>')->implode(' · ')))
                    ->placeholder('No bill'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (?string $state) => ucfirst((string) $state))
                    ->color(fn (?string $state) => match ($state) {
                        Expense::APPROVED => 'success', Expense::REJECTED => 'danger', default => 'warning',
                    })
                    ->description(fn (Expense $r) => match ($r->status) {
                        Expense::APPROVED => trim('by ' . ($r->approver?->name ?? '—') . ' ' . ($r->approved_at?->format('d M Y') ?? '')),
                        Expense::REJECTED => $r->reject_reason,
                        // a row whose approval was undone says so (who, when, why)
                        default => $r->reject_reason ?: 'by ' . ($r->submitter?->name ?? '—'),
                    })
                    ->wrap(),
                Tables\Columns\TextColumn::make('remarks')->wrap()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Entered at')->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')->options(Expense::groupedCategoryOptions())->multiple(),
                Tables\Filters\SelectFilter::make('source')->label('Entered')
                    ->options(['calibration' => 'Calibrated (board figures)', 'manual' => 'Typed by hand'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'calibration' => $query->where('source', 'calibration'),
                        'manual' => $query->whereNull('source'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->options([Expense::SUBMITTED => 'Submitted', Expense::APPROVED => 'Approved', Expense::REJECTED => 'Rejected']),
                CommonFilters::dateRange('expense_date', 'Paid'),
                Tables\Filters\SelectFilter::make('rank_id')->label('TBP Stage')
                    ->options(fn () => Rank::where('depth', '>', 0)->orderBy('depth')->get()
                        ->mapWithKeys(fn (Rank $r) => [$r->id => Translatable::pick($r->name) ?: $r->code])->all()),
                Tables\Filters\SelectFilter::make('branch_id')->label('Branch / office')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id')->all())->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalWidth('5xl'),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Expense $r) => static::canApprove($r))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Expense $r) => 'Approve ' . $r->expense_no . ' — ' . Money::inr((float) $r->amount))
                    ->modalDescription('An approved expense goes into the auditor report and can no longer be edited or deleted.')
                    ->action(function (Expense $r) {
                        $r->update(['status' => Expense::APPROVED, 'approved_by' => auth()->id(), 'approved_at' => now(), 'reject_reason' => null]);
                        Notification::make()->success()->title($r->expense_no . ' approved')->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Expense $r) => static::canApprove($r))
                    ->form([Forms\Components\Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(255)])
                    ->action(function (Expense $r, array $data) {
                        $r->update(['status' => Expense::REJECTED, 'approved_by' => auth()->id(), 'approved_at' => now(), 'reject_reason' => $data['reason']]);
                        Notification::make()->warning()->title($r->expense_no . ' rejected')->send();
                    }),
                Tables\Actions\Action::make('undoApproval')
                    ->label('Undo approval')
                    ->icon('heroicon-o-arrow-uturn-left')->color('warning')
                    ->visible(fn (Expense $r) => static::canUndoApproval($r))
                    ->modalHeading(fn (Expense $r) => 'Undo approval of ' . $r->expense_no . ' — ' . Money::inr((float) $r->amount))
                    ->modalDescription('The row goes back to Submitted: it leaves the auditor reports and can be edited, approved again or deleted. The undo is recorded with your name and the reason.')
                    ->form([Forms\Components\Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(150)])
                    ->action(function (Expense $r, array $data) {
                        static::undoApproval($r, $data['reason']);
                        Notification::make()->warning()->title('Approval of ' . $r->expense_no . ' undone')->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('approveSelected')
                    ->label('Approve selected')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn () => (bool) auth()->user()?->isSuperAdmin())
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        $n = 0;
                        foreach ($records->where('status', Expense::SUBMITTED) as $r) {
                            $r->update(['status' => Expense::APPROVED, 'approved_by' => auth()->id(), 'approved_at' => now(), 'reject_reason' => null]);
                            $n++;
                        }
                        Notification::make()->success()->title($n . ' expense(s) approved')->send();
                    }),
                Tables\Actions\BulkAction::make('undoApprovalSelected')
                    ->label('Undo approval of selected')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                    ->visible(fn () => (bool) auth()->user()?->isSuperAdmin())
                    ->modalDescription('The approved rows among the selected go back to Submitted and leave the auditor reports. The undo is recorded with your name and the reason.')
                    ->form([Forms\Components\Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(150)])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                        $n = 0;
                        foreach ($records->where('status', Expense::APPROVED) as $r) {
                            static::undoApproval($r, $data['reason']);
                            $n++;
                        }
                        Notification::make()->warning()->title('Approval undone on ' . $n . ' expense(s)')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
