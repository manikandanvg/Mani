<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\BranchAttendanceResource\Pages;
use App\Models\BranchAttendance;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The branch opening/closing register — written by RFID taps at each branch's
 * L-BOX. First tap of the day = branch OPENED (online); check-out taps stamp
 * the closing time. A branch with no opened row today is OFFLINE.
 */
class BranchAttendanceResource extends BaseResource
{
    use BranchScoped;

    protected static ?string $model = BranchAttendance::class;

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $modelLabel = 'Branch Attendance';

    protected static ?string $pluralModelLabel = 'Branch Attendance';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;   // rows are written by RFID taps at the box
    }

    public static function table(Table $table): Table
    {
        $hq = ! (bool) auth()->user()?->isDistributor();

        return $table
            ->defaultSort('date', 'desc')
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('date')->date()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable()->sortable()
                    ->description(fn (BranchAttendance $r) => $r->branch?->city),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->getStateUsing(fn (BranchAttendance $r) => $r->opened_at
                        ? ($r->date->isToday() && ! $r->closed_at ? 'open' : 'closed')
                        : 'offline')
                    ->color(fn ($state) => match ($state) {
                        'open' => 'success', 'closed' => 'info', default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('opened_at')->label('Opened')->time('H:i')->sortable(),
                Tables\Columns\TextColumn::make('openedBy.employee_code')->label('Opened by')->searchable()
                    ->description(fn (BranchAttendance $r) => $r->openedBy?->member?->name),
                Tables\Columns\TextColumn::make('closed_at')->label('Closed')->time('H:i')->sortable(),
                Tables\Columns\TextColumn::make('closedBy.employee_code')->label('Closed by')->searchable()
                    ->description(fn (BranchAttendance $r) => $r->closedBy?->member?->name),
                Tables\Columns\TextColumn::make('device.name')->label('Box')->searchable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')->label('Branch')
                    ->options(fn () => \App\Models\Branch::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible($hq),
                Tables\Filters\SelectFilter::make('state')->label('Status')
                    ->options(['open' => 'Opened', 'offline' => 'Offline (never opened)'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->whereNotNull('opened_at'),
                        'offline' => $query->whereNull('opened_at'),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('to'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'From ' . $data['from'] : null,
                        ($data['to'] ?? null) ? 'To ' . $data['to'] : null,
                    ])),
            ])
            ->filtersFormColumns(3);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranchAttendances::route('/'),
        ];
    }
}
