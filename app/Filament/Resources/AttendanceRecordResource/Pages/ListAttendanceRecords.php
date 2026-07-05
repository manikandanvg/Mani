<?php

namespace App\Filament\Resources\AttendanceRecordResource\Pages;

use App\Filament\Resources\AttendanceRecordResource;
use App\Models\EmployeeProfile;
use App\Services\Payroll\AttendanceService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceRecords extends ListRecords
{
    protected static string $resource = AttendanceRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Manual entry goes through the service (upserts the unique employee+date row).
            Actions\Action::make('markAttendance')
                ->label('Mark attendance')
                ->icon('heroicon-o-pencil-square')
                ->form([
                    Forms\Components\Select::make('employee_profile_id')
                        ->label('Employee')
                        ->options(fn () => EmployeeProfile::where('status', 'active')->with('member')->get()
                            ->mapWithKeys(fn (EmployeeProfile $e) => [$e->id => $e->employee_code . ' — ' . ($e->member?->name ?? '')]))
                        ->searchable()->required(),
                    Forms\Components\DatePicker::make('date')->default(now())->required(),
                    Forms\Components\Select::make('status')
                        ->options(['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent', 'leave' => 'Leave (unpaid)', 'paid_leave' => 'Paid leave'])
                        ->default('present')->required(),
                    Forms\Components\TextInput::make('note'),
                ])
                ->action(function (array $data) {
                    $employee = EmployeeProfile::findOrFail($data['employee_profile_id']);
                    app(AttendanceService::class)->markManual(
                        $employee, $data['date'], $data['status'], $data['note'] ?? null, auth()->id(),
                    );
                    Notification::make()->title('Attendance recorded')->success()->send();
                }),
        ];
    }
}
