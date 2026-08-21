<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\AttendanceRecordResource\Pages;
use App\Models\AttendanceRecord;
use App\Models\EmployeeProfile;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Attendance ledger — app selfie+GPS check-ins land here; HQ can mark manual
 * entries from the list page header ("Mark attendance"). Payroll runs read this.
 */
class AttendanceRecordResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = AttendanceRecord::class;

    protected static ?string $navigationGroup = 'Payroll';

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $modelLabel = 'Attendance';

    protected static ?string $pluralModelLabel = 'Attendance';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;   // manual entries go through the "Mark attendance" header action
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->date()->sortable(),
                Tables\Columns\TextColumn::make('employee.employee_code')->label('Employee')->searchable()
                    ->description(fn (AttendanceRecord $r) => $r->employee?->member?->name),
                // Request-host URLs (not disk()/Storage::url, which bake in the dev-only
                // APP_URL) so selfies load for remote/LAN admin sessions too.
                Tables\Columns\ImageColumn::make('selfie_path')->label('Selfie')
                    ->getStateUsing(fn (AttendanceRecord $r) => $r->selfie_path ? url('storage/' . ltrim($r->selfie_path, '/')) : null)
                    ->circular()->defaultImageUrl(null),
                Tables\Columns\ImageColumn::make('checkout_selfie_path')->label('Out selfie')
                    ->getStateUsing(fn (AttendanceRecord $r) => $r->checkout_selfie_path ? url('storage/' . ltrim($r->checkout_selfie_path, '/')) : null)
                    ->circular()->defaultImageUrl(null)->toggleable(),
                Tables\Columns\TextColumn::make('check_in_at')->label('In')->time('H:i')->sortable(),
                Tables\Columns\TextColumn::make('check_out_at')->label('Out')->time('H:i'),
                Tables\Columns\TextColumn::make('location')->label('Location')
                    ->getStateUsing(fn (AttendanceRecord $r) => $r->lat !== null ? "{$r->lat}, {$r->lng}" : '—')
                    ->url(fn (AttendanceRecord $r) => $r->lat !== null
                        ? "https://maps.google.com/?q={$r->lat},{$r->lng}" : null, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('source')->badge()
                    ->color(fn ($state) => match ($state) {
                        'app' => 'success', 'manual' => 'warning', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'present', 'paid_leave' => 'success', 'half_day' => 'warning',
                        'leave' => 'info', default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('note')->limit(30)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('employee_profile_id')
                    ->label('Employee')
                    ->options(fn () => EmployeeProfile::with('member')->get()
                        ->mapWithKeys(fn (EmployeeProfile $e) => [$e->id => $e->employee_code . ' — ' . ($e->member?->name ?? '')])),
                Tables\Filters\SelectFilter::make('status')
                    ->options(['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent', 'leave' => 'Leave (unpaid)', 'paid_leave' => 'Paid leave']),
                Tables\Filters\Filter::make('date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('to'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceRecords::route('/'),
        ];
    }
}
