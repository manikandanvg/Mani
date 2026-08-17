<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\MobileDeviceResource\Pages;
use App\Models\MobileDevice;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Mobile Registry (item 17b, board 2026-08-12): every phone the app has signed in
 * from — login phone, distributor, install uid, platform, biometric enrollment.
 * Rows are written by the app at login / biometric toggle; read-only here.
 */
class MobileDeviceResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = MobileDevice::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $modelLabel = 'Mobile Device';

    protected static ?string $pluralModelLabel = 'Mobile Registry';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')->searchable()
                    ->description(fn (MobileDevice $d) => $d->member?->name),
                Tables\Columns\TextColumn::make('device_name')->label('Device')->searchable()
                    ->description(fn (MobileDevice $d) => $d->device_uid),
                Tables\Columns\TextColumn::make('platform')->badge()
                    ->color(fn (?string $state) => $state === 'ios' ? 'gray' : 'success'),
                Tables\Columns\IconColumn::make('biometric_enabled')->label('Biometric')->boolean(),
                Tables\Columns\TextColumn::make('app_version')->label('App')->toggleable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('First login')->date()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('biometric_enabled')->label('Biometric enrolled'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Removes this device from the registry. The app re-registers on its next login.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMobileDevices::route('/'),
        ];
    }
}
