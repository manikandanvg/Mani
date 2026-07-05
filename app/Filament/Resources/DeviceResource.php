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

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Device')->columns(3)->schema([
                Forms\Components\TextInput::make('name')->required()
                    ->placeholder('e.g. Rajapalayam Counter Box'),
                Forms\Components\TextInput::make('serial_no')->label('Serial number')->required()
                    ->unique(ignoreRecord: true)->placeholder('LBX-LITE-0001'),
                Forms\Components\Select::make('board_type')->label('Board')
                    ->options(['lite' => 'Lite (ESP32, Wi-Fi)', 'pro' => 'Pro (TTGO, 4G + GPS)'])
                    ->default('lite')->required(),
                Forms\Components\Select::make('language')->label('Spoken language')
                    ->options(['en' => 'English', 'ta' => 'Tamil (தமிழ்)'])
                    ->default('en')->required()
                    ->helperText('Voice announcements and tap replies are spoken in this language.'),
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
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()
                    ->description(fn (Device $d) => $d->serial_no),
                Tables\Columns\TextColumn::make('board_type')->label('Board')->badge()
                    ->color(fn ($state) => $state === 'pro' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch'),
                Tables\Columns\TextColumn::make('online')->label('Box')->badge()
                    ->getStateUsing(fn (Device $d) => $d->is_displaced ? 'MOVED!' : ($d->isOnline() ? 'online' : 'offline'))
                    ->color(fn ($state) => match ($state) {
                        'online' => 'success', 'MOVED!' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('branch_open')->label('Branch today')->badge()
                    ->getStateUsing(fn (Device $d) => $d->branch_id && \App\Models\BranchAttendance::isOpenToday($d->branch_id)
                        ? 'open' : 'not opened')
                    ->color(fn ($state) => $state === 'open' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('battery_pct')->label('Battery')
                    ->formatStateUsing(fn ($state) => $state !== null ? "{$state}%" : '—'),
                Tables\Columns\TextColumn::make('rssi')->label('Signal')
                    ->formatStateUsing(fn ($state) => $state !== null ? "{$state} dBm" : '—'),
                Tables\Columns\TextColumn::make('firmware_version')->label('Firmware'),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Last seen')->since(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'provisioned' => 'warning', default => 'danger',
                    }),
            ])
            ->actions([
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
                Tables\Actions\Action::make('staticQr')
                    ->label('Static QR')
                    ->icon('heroicon-o-qr-code')
                    ->url(fn (Device $record) => app(\App\Services\Qr\QrCodeService::class)
                        ->store($record->uuid, "lbox-{$record->serial_no}"), shouldOpenInNewTab: true)
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
