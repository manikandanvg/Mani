<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Models\EmployeeVisit;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * L-BOX → Employee Visits (board 2026-08-11): every RFID check-in away from home —
 * other dealer branches and the meeting-arena box — with device-wise and
 * employee-wise monitoring. Read-only; rows are written by the boxes.
 */
class EmployeeVisitResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = EmployeeVisit::class;

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $modelLabel = 'Employee Visit';

    protected static ?string $pluralModelLabel = 'Employee Visits';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('visited_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('visited_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('employee.member.name')->label('Employee')
                    ->description(fn (EmployeeVisit $r) => trim(
                        ($r->employee?->employee_code ? 'Emp: ' . $r->employee->employee_code : '')
                        . ($r->employee?->member?->member_code ? ' · Dist: ' . $r->employee->member->member_code : '')
                    , ' ·'))
                    ->searchable(query: fn ($query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('employee_code', 'like', "%{$search}%")
                            ->orWhereHas('member', fn ($m) => $m->where('name', 'like', "%{$search}%")
                                ->orWhere('member_code', 'like', "%{$search}%"))
                    )),
                Tables\Columns\TextColumn::make('branch.name')->label('Visited branch')
                    ->placeholder('Meeting arena')
                    ->badge()
                    ->color(fn (EmployeeVisit $r) => $r->branch_id ? 'info' : 'warning'),
                Tables\Columns\TextColumn::make('device.name')->label('Box')
                    ->description(fn (EmployeeVisit $r) => $r->device?->serial_no),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('employee_profile_id')->label('Employee')
                    ->relationship('employee', 'employee_code'),
                Tables\Filters\SelectFilter::make('device_id')->label('Box')
                    ->relationship('device', 'name'),
                Tables\Filters\SelectFilter::make('branch_id')->label('Branch')
                    ->relationship('branch', 'name'),
                Tables\Filters\Filter::make('meeting_arena')->label('Meeting arena only')
                    ->query(fn ($query) => $query->whereNull('branch_id'))
                    ->toggle(),
                \App\Filament\Support\CommonFilters::dateRange('visited_at', 'Visited'),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\EmployeeVisitResource\Pages\ListEmployeeVisits::route('/'),
        ];
    }
}
