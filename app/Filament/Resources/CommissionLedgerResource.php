<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\CommissionLedgerResource\Pages;
use App\Filament\Resources\CommissionLedgerResource\RelationManagers;
use App\Models\CommissionEntry;
use App\Models\CommissionLedger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CommissionLedgerResource extends BaseResource
{
    use HqOnly;

    // Backed by the commission_entries VIEW — IC/GAP + CBC + all 5 margins in one
    // list (board 2026-08-10: Gold Margin etc. must be visible here, not only on
    // the approval screen). Strictly read-only; labels stay "Commission Ledgers".
    protected static ?string $model = CommissionEntry::class;

    protected static ?string $modelLabel = 'Commission Ledger';

    protected static ?string $pluralModelLabel = 'Commission Ledgers';

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    /**
     * Viewing ledger only (board 2026-08-09): rows are written by the commission
     * engines and settled through Commission Approval — never hand-entered or edited.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('type')
                    ->required(),
                Forms\Components\TextInput::make('member_id')
                    ->label('Distributor ID')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('from_member_id')
                    ->label('From Distributor ID')
                    ->numeric(),
                Forms\Components\TextInput::make('bond_id')
                    ->numeric(),
                Forms\Components\TextInput::make('invoice_no')
                    ->maxLength(40),
                Forms\Components\TextInput::make('level')
                    ->label('Placement Layer')
                    ->numeric(),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('status')
                    ->required(),
                Forms\Components\TextInput::make('pay_via'),
                Forms\Components\DatePicker::make('earned_on')
                    ->required(),
                Forms\Components\DatePicker::make('paid_on'),
                Forms\Components\TextInput::make('branch_id')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('earned_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (?string $state) => \App\Services\CommissionApprovalService::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('member.member_code')
                    ->label('Distributor')
                    ->description(fn (CommissionEntry $r) => $r->member?->name)
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fromMember.member_code')
                    ->label('From Distributor')
                    ->description(fn (CommissionEntry $r) => $r->fromMember?->name)
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('bond_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('invoice_no')
                    ->searchable(),
                Tables\Columns\TextColumn::make('level')
                    ->label('Placement Layer')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->baseMoney()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tds')->label('TDS')->baseMoney()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('service_charge')->label('Service')->baseMoney()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('net_amount')->label('Net')->baseMoney()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'paid' => 'success',
                        'approved', 'passed', 'used' => 'info',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('earned_on')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid_on')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Board fix 2026-08-09: same pickers as Commission Approval — type dropdown,
            // status, earned-on date range. This screen is a viewing ledger only.
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Commission type')
                    // Same friendly names as the Commission Approval page — one vocabulary
                    // for the whole commissions module (board 2026-08-10).
                    ->options(\App\Services\CommissionApprovalService::TYPES),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'passed' => 'Passed (awaiting approval)',
                        'approved' => 'Approved',
                        'paid' => 'Paid',
                        'used' => 'Used (CBC)',
                    ]),
                Tables\Filters\Filter::make('earned_on')
                    // Defaults to the LAST 7 DAYS so the screen never cold-loads the whole
                    // history — the operator widens the interval from the filter itself.
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Earned from')->native(false)
                            ->default(now()->subDays(7)->toDateString()),
                        \Filament\Forms\Components\DatePicker::make('to')->label('Earned to')->native(false)
                            ->default(now()->toDateString()),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('earned_on', '>=', $v))
                        ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('earned_on', '<=', $v)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'From ' . \Illuminate\Support\Carbon::parse($data['from'])->format('d M Y') : null,
                        ($data['to'] ?? null) ? 'To ' . \Illuminate\Support\Carbon::parse($data['to'])->format('d M Y') : null,
                    ])),
            ])
            ->actions([
                // read-only: crediting happens ONLY through Commission Approval
            ])
            // no bulk actions — rows live in a SQL view; deletes must target the
            // underlying source tables
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionLedgers::route('/'),
        ];
    }
}
