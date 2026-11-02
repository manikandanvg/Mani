<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\StaffResource\Pages;
use App\Models\Branch;
use App\Models\User;
use App\Services\Staff\StaffCredentialsService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
                // User 2026-11-02: 1,180 branches share the name "LORD JEWELLER" — show
                // "NAME (city / phone / in-charge)" and search any of those fields server-side.
                Forms\Components\Select::make('branch_id')
                    ->label('Branch')
                    ->searchable()
                    ->searchPrompt('Type a branch name, city, phone or in-charge…')
                    ->getSearchResultsUsing(fn (string $search) => Branch::pickOptions($search))
                    ->getOptionLabelUsing(fn ($value) => Branch::find($value)?->pickLabel())
                    ->options(fn () => Branch::pickOptions(null, 50))
                    ->helperText('Shown as name (city / phone / in-charge) so the right branch is picked.'),
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
                Tables\Columns\TextColumn::make('phone')->label('Phone')->searchable()->sortable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('roles.name')->badge()->label('Roles'),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable()->searchable()
                    ->description(fn (User $record) => $record->branch?->city),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->actions([
                // User 2026-11-02: send the login (new temporary password) by WhatsApp + app push
                Tables\Actions\Action::make('sendDetails')
                    ->label('Send login details')->icon('heroicon-o-paper-airplane')->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Send login details?')
                    ->modalDescription(fn (User $record) => 'A new temporary password is set for ' . $record->email . ' and sent by WhatsApp to '
                        . (($record->phone ?: $record->branch?->phone) ?: 'NO phone on file')
                        . ($record->memberAccount ? ' and as an app push to ' . $record->memberAccount->member_code : ' (no member account, so no app push)')
                        . '. The old password stops working.')
                    ->action(function (User $record) {
                        $res = app(StaffCredentialsService::class)->send($record, auth()->id());
                        static::notifySent($res);
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([
                Tables\Actions\BulkAction::make('bulkSendDetails')
                    ->label('Send login details')->icon('heroicon-o-paper-airplane')->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Each selected login gets a new temporary password, sent by WhatsApp to its phone (branch phone as fallback) and as an app push where the login has a member account. Old passwords stop working.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $svc = app(StaffCredentialsService::class);
                        [$wa, $push, $none] = [0, 0, 0];
                        foreach ($records as $u) {
                            $res = $svc->send($u, auth()->id());
                            $wa += $res['whatsapp'] ? 1 : 0;
                            $push += $res['push'] ? 1 : 0;
                            $none += (! $res['whatsapp'] && ! $res['push']) ? 1 : 0;
                        }
                        Notification::make()->{$none ? 'warning' : 'success'}()
                            ->title($records->count() . ' login' . ($records->count() === 1 ? '' : 's') . ' reset')
                            ->body("WhatsApp sent: {$wa} · app push: {$push}" . ($none ? " · reached nobody: {$none} (no phone, no member account)" : ''))
                            ->persistent()->send();
                    }),
                Tables\Actions\DeleteBulkAction::make(),
            ])]);
    }

    protected static function notifySent(array $res): void
    {
        $parts = [];
        $parts[] = $res['whatsapp'] ? 'WhatsApp sent to ' . $res['phone'] : 'WhatsApp NOT sent' . ($res['whatsapp_note'] ? ' (' . $res['whatsapp_note'] . ')' : '');
        $parts[] = $res['push'] ? 'app push + inbox sent' : 'no app push (no member account)';
        $ok = $res['whatsapp'] || $res['push'];
        Notification::make()->{$ok ? 'success' : 'danger'}()
            ->title($ok ? 'Login details sent' : 'Login details NOT delivered')
            ->body(implode(' · ', $parts) . ($ok ? '' : '. The password was still reset — set a phone or member code and send again.'))
            ->persistent()->send();
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
