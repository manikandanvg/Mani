<?php

namespace App\Filament\Resources\DeviceResource\RelationManagers;

use App\Models\VoiceAnnouncement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The box's voice queue — see what it has spoken (acked), fetched (delivered)
 * or still owes (pending). Read-only debugging/demo aid.
 */
class AnnouncementsRelationManager extends RelationManager
{
    protected static string $relationship = 'announcements';

    protected static ?string $title = 'Voice queue';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->color(fn ($state) => match ($state) {
                        'withdrawal' => 'warning', 'payment' => 'success', 'test' => 'gray', default => 'info',
                    }),
                Tables\Columns\TextColumn::make('message')->limit(60)->tooltip(fn (VoiceAnnouncement $a) => $a->message),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'acked' => 'success', 'delivered' => 'info', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Queued')->since(),
                Tables\Columns\TextColumn::make('acked_at')->label('Spoken')->since(),
            ]);
    }
}
