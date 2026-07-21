<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Models\EmployeeProfile;
use App\Models\Member;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Payroll employees — ranked distributors enrolled per the 2026-07 board mandate.
 * Salary/TDS default from the TBP stage; PF/ESI toggles + statutory numbers here.
 */
class EmployeeResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = EmployeeProfile::class;

    protected static ?string $navigationGroup = 'Payroll';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $modelLabel = 'Employee';

    protected static ?string $pluralModelLabel = 'Employees';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Employee')->columns(3)->schema([
                Forms\Components\Select::make('member_id')
                    ->label('Distributor')
                    ->options(fn () => Member::query()->orderBy('name')->limit(500)
                        ->get()->mapWithKeys(fn (Member $m) => [$m->id => "{$m->name} ({$m->member_code})"]))
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit'),
                Forms\Components\TextInput::make('employee_code')->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('designation'),
                Forms\Components\DatePicker::make('date_of_joining')->required()->default(now()),
                Forms\Components\TextInput::make('monthly_salary')->numeric()->required()->default(0)
                    ->helperText('Defaults from the TBP stage; override per employee here.'),
                Forms\Components\Select::make('status')
                    ->options(['active' => 'Active', 'suspended' => 'Suspended', 'exited' => 'Exited'])
                    ->default('active')->required(),
            ]),
            Forms\Components\Section::make('Statutory (PF / ESI / TDS)')->columns(3)->schema([
                Forms\Components\Toggle::make('pf_enabled')->label('PF (EPF) enabled')->default(true),
                Forms\Components\TextInput::make('uan')->label('UAN'),
                Forms\Components\TextInput::make('basic_pct')->label('Basic % of gross')->numeric()->default(50)
                    ->helperText('PF is computed on basic (ceiling ₹15,000).'),
                Forms\Components\Toggle::make('esi_enabled')->label('ESI enabled')->default(true)
                    ->helperText('Applies only while monthly salary ≤ ₹21,000.'),
                Forms\Components\TextInput::make('esic_number')->label('ESIC number'),
                Forms\Components\TextInput::make('tds_pct')->label('TDS % (override)')->numeric()
                    ->helperText('Leave empty to use the TBP stage default.'),
                Forms\Components\TextInput::make('geofence_radius_m')
                    ->label('Check-in geofence (metres)')->numeric()
                    ->helperText('Only allow app check-in within this distance of the branch map pin. Empty = anywhere (field staff).'),
                Forms\Components\TextInput::make('rfid_tag')
                    ->label('RFID card UID (L-BOX)')
                    ->unique(ignoreRecord: true)
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        // Mirror guard of BranchResource: an employee card must never
                        // collide with a BRANCH card — the box matches the branch card
                        // first, so this employee's taps would open/close the branch
                        // instead of checking them in.
                        $uid = strtoupper(trim((string) $value));
                        if ($uid !== '' && \App\Models\Branch::where('rfid_tag', $uid)->exists()) {
                            $fail('This card is already registered as a BRANCH open/close card.');
                        }
                    })
                    ->helperText('Card UID for attendance taps at the branch L-BOX. Empty = app check-in only.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee_code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable()
                    ->description(fn (EmployeeProfile $r) => $r->member?->member_code),
                Tables\Columns\TextColumn::make('designation')->searchable(),
                Tables\Columns\TextColumn::make('monthly_salary')->baseMoney()->sortable(),
                Tables\Columns\IconColumn::make('pf_enabled')->label('PF')->boolean(),
                Tables\Columns\IconColumn::make('esi_enabled')->label('ESI')->boolean(),
                Tables\Columns\TextColumn::make('date_of_joining')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'warning'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['active' => 'Active', 'suspended' => 'Suspended', 'exited' => 'Exited']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
