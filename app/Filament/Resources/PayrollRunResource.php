<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\PayrollRunResource\Pages;
use App\Filament\Resources\PayrollRunResource\RelationManagers\PayslipsRelationManager;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollService;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Monthly payroll runs — generated from attendance ("Generate payroll" on the list),
 * then Approve → Mark paid. Payslips (with PF/ESI/TDS breakdown) hang off each run.
 */
class PayrollRunResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = PayrollRun::class;

    protected static ?string $navigationGroup = 'Payroll';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $modelLabel = 'Payroll Run';

    protected static ?string $pluralModelLabel = 'Payroll Runs';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;   // runs are produced by the "Generate payroll" action
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_year', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')
                    ->getStateUsing(fn (PayrollRun $r) => $r->periodLabel())
                    ->sortable(['period_year', 'period_month']),
                Tables\Columns\TextColumn::make('payslips_count')->counts('payslips')->label('Employees'),
                Tables\Columns\TextColumn::make('gross_total')->baseMoney(),
                Tables\Columns\TextColumn::make('deduction_total')->label('Deductions')->baseMoney(),
                Tables\Columns\TextColumn::make('net_total')->baseMoney(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'approved' => 'info', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('approved_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->visible(fn (PayrollRun $r) => $r->status === 'draft')
                    ->requiresConfirmation()
                    ->action(function (PayrollRun $record) {
                        app(PayrollService::class)->approve($record, auth()->id());
                        Notification::make()->title("Payroll {$record->periodLabel()} approved")->success()->send();
                    }),
                Tables\Actions\Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (PayrollRun $r) => $r->status === 'approved')
                    ->requiresConfirmation()
                    ->form([
                        \Filament\Forms\Components\TextInput::make('reference')
                            ->label('Payment reference (bank batch etc.)'),
                    ])
                    ->modalDescription('Mark every payslip in this run as disbursed. The actual bank transfer happens outside the system.')
                    ->action(function (PayrollRun $record, array $data) {
                        app(PayrollService::class)->markPaid($record, $data['reference'] ?? null);
                        Notification::make()->title("Payroll {$record->periodLabel()} marked paid")->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PayslipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollRuns::route('/'),
            'view' => Pages\ViewPayrollRun::route('/{record}'),
        ];
    }
}
