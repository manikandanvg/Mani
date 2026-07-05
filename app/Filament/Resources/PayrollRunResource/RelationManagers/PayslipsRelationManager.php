<?php

namespace App\Filament\Resources\PayrollRunResource\RelationManagers;

use App\Models\Payslip;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PayslipsRelationManager extends RelationManager
{
    protected static string $relationship = 'payslips';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('employee.employee_code')->label('Employee')->searchable()
                    ->description(fn (Payslip $r) => $r->employee?->member?->name),
                Tables\Columns\TextColumn::make('employee.designation')->label('Designation'),
                Tables\Columns\TextColumn::make('payable_days')->label('Days'),
                Tables\Columns\TextColumn::make('gross')->baseMoney(),
                Tables\Columns\TextColumn::make('pf_employee')->label('PF')->baseMoney(),
                Tables\Columns\TextColumn::make('esi_employee')->label('ESI')->baseMoney(),
                Tables\Columns\TextColumn::make('tds')->label('TDS')->baseMoney(),
                Tables\Columns\TextColumn::make('net')->baseMoney()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->baseMoney()->label('Total net')),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('Payslip PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (Payslip $record) => route('payslip.pdf', $record), shouldOpenInNewTab: true),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
