<?php

namespace App\Filament\Resources\MeetingResource\RelationManagers;

use App\Models\MeetingAttendance;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only attendance list on a meeting's edit page (board phase-1, 2026-08-21).
 * 'app' rows = the member tapped Join; 'zoom' rows = Zoom's participant webhooks
 * (authoritative duration).
 */
class AttendancesRelationManager extends RelationManager
{
    protected static string $relationship = 'attendances';

    protected static ?string $title = 'Attendance';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('joined_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('member.name')->label('Member')
                    ->placeholder('—')->searchable()
                    ->description(fn (MeetingAttendance $a) => $a->member?->member_code),
                Tables\Columns\TextColumn::make('participant_name')->label('Zoom name')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('source')->badge()
                    ->color(fn ($state) => $state === 'zoom' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('joined_at')->dateTime('d M, h:i A')->sortable(),
                Tables\Columns\TextColumn::make('left_at')->dateTime('d M, h:i A')->placeholder('—'),
                Tables\Columns\TextColumn::make('duration_min')->label('Minutes')->numeric()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->options(['app' => 'App join', 'zoom' => 'Zoom verified']),
            ]);
    }
}
