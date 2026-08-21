<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Branch;
use App\Models\Device;
use App\Services\Lbox\AnnouncementService;
use App\Services\Lbox\DeviceService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * L-BOX fleet (internal-only smart branch boxes). Create a device here, hand its
 * pairing code to whoever assembles/installs the box; it redeems the code at first
 * boot. Telemetry (battery/signal/firmware) refreshes with every 60s heartbeat.
 */
class DeviceResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = Device::class;

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $modelLabel = 'Device';

    protected static ?string $pluralModelLabel = 'Devices';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Device')->columns(3)->schema([
                Forms\Components\TextInput::make('name')->required()
                    ->placeholder('e.g. Rajapalayam Counter Box'),
                Forms\Components\TextInput::make('serial_no')->label('Serial number')->required()
                    ->unique(ignoreRecord: true)->placeholder('LBX-LITE-0001'),
                Forms\Components\Select::make('board_type')->label('Board')
                    ->options([
                        'lite' => 'Lite (ESP32, Wi-Fi)',
                        'pro' => 'Pro (TTGO, 4G + GPS)',
                        // Board 2026-08-21: the fleet standard — ESP32-S3 N16R8.
                        'standard' => 'Standard (ESP32-S3, Wi-Fi + BLE)',
                    ])
                    ->default('standard')->required(),
                Forms\Components\Select::make('language')->label('Spoken language')
                    ->options(['en' => 'English', 'ta' => 'Tamil (தமிழ்)'])
                    ->default('en')->required()
                    ->helperText('Voice announcements and tap replies are spoken in this language.'),
                Forms\Components\Select::make('volume_level')->label('Speaker volume (HQ)')
                    ->options([1 => '1 — very soft', 2 => '2 — soft', 3 => '3 — medium', 4 => '4 — loud (default)', 5 => '5 — maximum'])
                    ->placeholder('Leave to the box')
                    ->helperText('Applied on the box\'s next heartbeat (within a minute). Staff can still adjust locally by touch pad or voice.'),
                Forms\Components\Select::make('branch_id')->label('Branch')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Attendance taps and payment announcements bind to this branch.'),
                Forms\Components\Select::make('status')
                    ->options([
                        'provisioned' => 'Provisioned (awaiting pairing)',
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                        'retired' => 'Retired',
                    ])
                    ->default('provisioned')->required(),
                Forms\Components\TextInput::make('notes'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        // Iconic card view (board 2026-08-11): one responsive card per box — identity,
        // live state, vitals — instead of a flat data-table row.
        return $table
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    // Row 1: identity | live badge
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('name')->searchable()
                            ->weight('bold')->size(Tables\Columns\TextColumn\TextColumnSize::Large)
                            ->icon('heroicon-m-cube')->iconColor('warning')
                            ->description(fn (Device $d) => 'S/N ' . $d->serial_no . ' · ' . strtoupper((string) $d->board_type)),
                        Tables\Columns\TextColumn::make('online')->badge()->grow(false)
                            ->getStateUsing(fn (Device $d) => $d->is_displaced ? 'MOVED!' : ($d->isOnline() ? 'online' : 'offline'))
                            ->color(fn ($state) => match ($state) {
                                'online' => 'success', 'MOVED!' => 'danger', default => 'gray',
                            }),
                    ]),
                    // Row 2: branch (truncated, full name on hover) | today badge
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('branch.name')
                            ->icon('heroicon-m-building-storefront')->iconColor('primary')
                            ->limit(24)
                            ->tooltip(fn (Device $d) => $d->branch?->name)
                            ->placeholder('Meeting arena / unassigned'),
                        Tables\Columns\TextColumn::make('branch_open')->badge()->grow(false)
                            ->getStateUsing(fn (Device $d) => $d->branch_id
                                ? (\App\Models\BranchAttendance::isOpenToday($d->branch_id) ? 'open today' : 'not opened')
                                : 'no branch')
                            ->color(fn ($state) => match ($state) {
                                'open today' => 'success', 'not opened' => 'warning', default => 'gray',
                            }),
                    ]),
                    // Row 3: vitals in a fixed 2×2 grid — no wrap surprises
                    Tables\Columns\Layout\Grid::make(['default' => 2])->schema([
                        Tables\Columns\TextColumn::make('battery_pct')
                            ->icon('heroicon-m-battery-50')
                            ->formatStateUsing(fn ($state) => $state !== null ? "{$state}%" : '—')
                            ->color(fn ($state) => $state !== null && $state < 20 ? 'danger' : 'gray'),
                        Tables\Columns\TextColumn::make('rssi')
                            ->icon('heroicon-m-signal')
                            ->formatStateUsing(fn ($state) => $state !== null ? "{$state} dBm" : '—'),
                        Tables\Columns\TextColumn::make('firmware_version')
                            ->icon('heroicon-m-cpu-chip')
                            ->placeholder('—'),
                        Tables\Columns\TextColumn::make('last_seen_at')
                            ->icon('heroicon-m-clock')
                            ->since()
                            ->placeholder('never'),
                    ]),
                    // Row 4: lifecycle status
                    Tables\Columns\TextColumn::make('status')->badge()
                        ->color(fn ($state) => match ($state) {
                            'active' => 'success', 'provisioned' => 'warning', default => 'danger',
                        }),
                ])->space(3),
            ])
            ->actions([
                // One kebab menu per card — six inline buttons blew the card width
                // and forced a horizontal scrollbar (board fix 2026-08-11).
                Tables\Actions\ActionGroup::make([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('pairingCode')
                    ->label('Pairing code')
                    ->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->modalDescription('Generates a NEW one-time pairing code (any previous code stops working). Enter it on the device at first boot.')
                    ->action(function (Device $record) {
                        $code = app(DeviceService::class)->regeneratePairingCode($record);
                        Notification::make()
                            ->title("Pairing code: {$code}")
                            ->body("Serial {$record->serial_no} — the code is shown once; note it down now.")
                            ->success()->persistent()->send();
                    }),
                // Board 2026-08-12 (web item 9): open the static QR as a branded card
                // modal (branch name + device + big QR) instead of a bare PNG tab.
                Tables\Actions\Action::make('staticQr')
                    ->label('Static QR')
                    ->icon('heroicon-o-qr-code')
                    ->modalHeading(__('L-BOX Static QR'))
                    ->modalContent(fn (Device $record) => view('filament.modals.device-static-qr', [
                        'device' => $record,
                        'qrUrl' => app(\App\Services\Qr\QrCodeService::class)
                            ->store($record->uuid, "lbox-{$record->serial_no}"),
                    ]))
                    ->modalWidth('md')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->tooltip('Print this QR on the box — distributors scan it to withdraw their wallet at this branch.'),
                Tables\Actions\Action::make('reAnchor')
                    ->label('Re-anchor')
                    ->icon('heroicon-o-map-pin')
                    ->color('warning')
                    ->visible(fn (Device $d) => $d->anchor_lat !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Approve this box\'s NEW location: the anchor is forgotten and the next GPS fix becomes home. Use only after a genuine, authorised relocation.')
                    ->action(function (Device $record) {
                        app(DeviceService::class)->reAnchor($record);
                        Notification::make()->title('Anchor cleared — next GPS fix re-anchors the box')->success()->send();
                    }),
                Tables\Actions\Action::make('branchCard')
                    ->label('Branch card')
                    ->icon('heroicon-o-identification')
                    ->disabled(fn (Device $d) => $d->branch_id === null)
                    ->tooltip(fn (Device $d) => $d->branch_id
                        ? 'Set or replace this branch\'s RFID card'
                        : 'Assign the device to a branch first (Edit → Branch)')
                    ->form([
                        Forms\Components\TextInput::make('rfid_tag')
                            ->label('Branch RFID card number')
                            ->default(fn (Device $record) => $record->branch?->rfid_tag)
                            ->maxLength(32)
                            ->helperText('Tap an unregistered card on this box — its number appears on the box screen. Paste it here. Replacing the number instantly retires the old card (lost-card reset). Leave empty to remove.'),
                    ])
                    ->action(function (Device $record, array $data) {
                        if (! $record->branch) {
                            Notification::make()->title('Assign the device to a branch first (Edit → Branch)')->danger()->send();

                            return;
                        }
                        $uid = strtoupper(trim((string) ($data['rfid_tag'] ?? ''))) ?: null;
                        if ($uid && \App\Models\Branch::where('rfid_tag', $uid)->where('id', '!=', $record->branch_id)->exists()) {
                            Notification::make()->title('That card is already registered to another branch')->danger()->send();

                            return;
                        }
                        if ($uid && \App\Models\EmployeeProfile::where('rfid_tag', $uid)->exists()) {
                            // The box matches the branch card BEFORE the employee lookup —
                            // reusing an employee's card would swallow their attendance taps.
                            Notification::make()->title('That card belongs to an EMPLOYEE — their attendance taps would break')->danger()->send();

                            return;
                        }
                        $record->branch->update(['rfid_tag' => $uid]);
                        Notification::make()
                            ->title($uid ? "Branch card set: {$uid}" : 'Branch card removed')
                            ->body("{$record->branch->name} — morning tap opens the branch, evening tap closes it.")
                            ->success()->send();
                    }),
                Tables\Actions\Action::make('testVoice')
                    ->label('Test voice')
                    ->icon('heroicon-o-speaker-wave')
                    ->visible(fn (Device $d) => $d->status === 'active')
                    ->form([
                        Forms\Components\TextInput::make('message')
                            ->default('Test announcement from Head Office')->required(),
                    ])
                    ->action(function (Device $record, array $data) {
                        app(AnnouncementService::class)->queue($record, 'test', $data['message']);
                        Notification::make()->title('Queued — the box speaks it on its next poll')->success()->send();
                    }),
                ])->icon('heroicon-m-ellipsis-vertical')->tooltip('Device actions'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\DeviceResource\RelationManagers\AnnouncementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
        ];
    }
}
