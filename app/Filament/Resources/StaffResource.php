<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\StaffResource\Pages;
use App\Models\Branch;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Master 1.3 — staff (accounts, billers, managers). Super-admin is excluded from
 * the list and only admins can manage staff. Role assignment drives menu visibility
 * (see Resource::canAccess on sensitive resources).
 */
class StaffResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    /** Hide super-admin accounts from the staff list. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Profile')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(150),
                Forms\Components\TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(20),
                Forms\Components\Select::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\Select::make('status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                    ->default('active')
                    ->required(),
            ]),
            Forms\Components\Section::make('Access')->columns(2)->schema([
                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->options(fn () => Role::where('name', '!=', 'super_admin')->pluck('name', 'id'))
                    ->required()
                    ->helperText('Roles control which menus this staff member can see.'),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $context) => $context === 'create')
                    ->dehydrated(fn ($state) => filled($state)) // User model 'hashed' cast hashes it
                    ->helperText('Leave blank to keep the current password.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->badge()->label('Roles'),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }
}
