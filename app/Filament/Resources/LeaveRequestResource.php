<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\LeaveRequestResource\Pages;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Services\Payroll\LeaveService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Leave requests filed from the app. HQ approves each as PAID or UNPAID leave —
 * approval writes the attendance rows payroll reads (worked days are never
 * overwritten) — or rejects it with a note the employee sees in the app.
 */
class LeaveRequestResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = LeaveRequest::class;

    protected static ?string $navigationGroup = 'Payroll';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $modelLabel = 'Leave Request';

    protected static ?string $pluralModelLabel = 'Leave Requests';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;   // requests are filed by employees from the app
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = LeaveRequest::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('employee.employee_code')->label('Employee')->searchable()
                    ->description(fn (LeaveRequest $r) => $r->employee?->member?->name),
                Tables\Columns\TextColumn::make('start_date')->label('From')->date()->sortable(),
                Tables\Columns\TextColumn::make('end_date')->label('To')->date(),
                Tables\Columns\TextColumn::make('days')->getStateUsing(fn (LeaveRequest $r) => $r->days()),
                Tables\Columns\TextColumn::make('reason')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('approved_type')->label('Type')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'paid_leave' ? 'Paid' : 'Unpaid')
                    ->color(fn ($state) => $state === 'paid_leave' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')->label('Requested')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'])
                    ->default('pending'),
                Tables\Filters\SelectFilter::make('employee_profile_id')
                    ->label('Employee')
                    ->options(fn () => EmployeeProfile::with('member')->get()
                        ->mapWithKeys(fn (EmployeeProfile $e) => [$e->id => $e->employee_code . ' — ' . ($e->member?->name ?? '')])),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (LeaveRequest $r) => $r->status === 'pending')
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('Approve as')
                            ->options(['paid_leave' => 'Paid leave (counts as a payable day)', 'leave' => 'Unpaid leave'])
                            ->default('paid_leave')
                            ->required(),
                        Forms\Components\TextInput::make('note')->label('Note to the employee'),
                    ])
                    ->modalDescription('Approval marks each requested day in the attendance ledger. Days already worked are kept.')
                    ->action(function (LeaveRequest $record, array $data) {
                        app(LeaveService::class)->approve($record, $data['type'], auth()->id(), $data['note'] ?? null);
                        Notification::make()->title('Leave approved')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $r) => $r->status === 'pending')
                    ->form([
                        Forms\Components\TextInput::make('note')->label('Reason shown to the employee'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (LeaveRequest $record, array $data) {
                        app(LeaveService::class)->reject($record, auth()->id(), $data['note'] ?? null);
                        Notification::make()->title('Leave rejected')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeaveRequests::route('/'),
        ];
    }
}
