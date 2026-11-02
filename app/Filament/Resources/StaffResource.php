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
                // User 2026-11-02 (board: WhatsApp is OTP-only): send the login (new temporary
                // password) as an app push + inbox entry to the login's member account
                Tables\Actions\Action::make('sendDetails')
                    ->label('Send login details')->icon('heroicon-o-paper-airplane')->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Send login details?')
                    ->modalDescription(fn (User $record) => $record->memberAccount
                        ? 'A new temporary password is set for ' . $record->email . ' and sent as an app push + inbox message to ' . $record->memberAccount->member_code . '. The old password stops working.'
                        : $record->email . ' has no member account (member code), so there is no app to push to. Nothing will be sent and the password stays as it is.')
                    ->disabled(fn (User $record) => $record->memberAccount === null)
                    ->action(function (User $record) {
                        static::notifySent(fn () => app(StaffCredentialsService::class)->send($record, auth()->id()));
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([
                Tables\Actions\BulkAction::make('bulkSendDetails')
                    ->label('Send login details')->icon('heroicon-o-paper-airplane')->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Each selected login gets a new temporary password, sent as an app push + inbox message to its member account. Logins without a member account are skipped and keep their password. Old passwords stop working.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $svc = app(StaffCredentialsService::class);
                        [$sent, $withDevice, $skipped] = [0, 0, []];
                        foreach ($records as $u) {
                            try {
                                $res = $svc->send($u, auth()->id());
                                $sent++;
                                $withDevice += $res['devices'] > 0 ? 1 : 0;
                            } catch (\Throwable $e) {
                                $skipped[] = $u->email;
                            }
                        }
                        Notification::make()->{$skipped ? 'warning' : 'success'}()
                            ->title($sent . ' login' . ($sent === 1 ? '' : 's') . ' sent as app push + inbox')
                            ->body("With a registered phone: {$withDevice} · inbox only (app not yet logged in): " . ($sent - $withDevice)
                                . ($skipped ? ' · skipped, no member account: ' . implode(', ', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? '…' : '') : ''))
                            ->persistent()->send();
                    }),
                Tables\Actions\DeleteBulkAction::make(),
            ])]);
    }

    protected static function notifySent(\Closure $send): void
    {
        try {
            $res = $send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Login details NOT sent')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()
            ->title('Login details sent to ' . $res['member_code'])
            ->body($res['devices'] > 0
                ? 'App push to ' . $res['devices'] . ' registered phone' . ($res['devices'] === 1 ? '' : 's') . ' + inbox message. The old password stops working.'
                : 'No phone registered yet for this member — the message waits in the app inbox and shows on the next app login. The old password stops working.')
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
