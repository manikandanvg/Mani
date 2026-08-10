<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\FirmwareResource\Pages;
use App\Models\Firmware;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * L-BOX OTA firmware repository. Upload a .bin per board, then Activate — every
 * device of that board sees the update on its next ota/check and installs it
 * (SHA-256 verified on-device, auto-rollback on failed boot per LBOX OS).
 */
class FirmwareResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = Firmware::class;

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-circle';

    protected static ?string $modelLabel = 'Firmware';

    protected static ?string $pluralModelLabel = 'Firmware';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Firmware build')->columns(2)->schema([
                Forms\Components\Select::make('board_type')->label('Board')
                    ->options(['lite' => 'Lite (ESP32)', 'pro' => 'Pro (TTGO)'])
                    ->required(),
                Forms\Components\TextInput::make('version')->required()
                    ->placeholder('1.0.0')
                    ->helperText('Semantic version — devices update only to a HIGHER version.'),
                Forms\Components\FileUpload::make('path')
                    ->label('Binary (.bin)')
                    ->disk('local')->directory('firmware')
                    ->acceptedFileTypes(['application/octet-stream'])
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('board_type')->label('Board')->badge()
                    ->color(fn ($state) => $state === 'pro' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('version')->searchable(),
                Tables\Columns\TextColumn::make('size_bytes')->label('Size')
                    ->formatStateUsing(fn ($state) => $state ? round($state / 1024) . ' KB' : '—'),
                Tables\Columns\TextColumn::make('sha256')->label('SHA-256')->limit(16)->tooltip(fn (Firmware $f) => $f->sha256),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
                Tables\Columns\TextColumn::make('notes')->limit(30)->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Uploaded')->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (Firmware $f) => ! $f->is_active)
                    ->requiresConfirmation()
                    ->modalDescription('Every device of this board type will install this version on its next OTA check.')
                    ->action(function (Firmware $record) {
                        Firmware::where('board_type', $record->board_type)->update(['is_active' => false]);
                        $record->update(['is_active' => true]);
                        Notification::make()->title("v{$record->version} is now live for {$record->board_type}")->success()->send();
                    }),
                Tables\Actions\Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->visible(fn (Firmware $f) => $f->is_active)
                    ->requiresConfirmation()
                    ->action(fn (Firmware $record) => $record->update(['is_active' => false])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFirmwares::route('/'),
            'create' => Pages\CreateFirmware::route('/create'),
        ];
    }
}
