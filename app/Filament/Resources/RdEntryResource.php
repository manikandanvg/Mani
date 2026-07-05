<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\RdEntryResource\Pages;
use App\Models\RdEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manage RD / Gold-Saving collections — the renewal installments recorded via the
 * RD Collection Entry page. Read-only list (entries are created by the collection flow).
 */
class RdEntryResource extends BaseResource
{
    use BranchScoped;

    protected static ?string $model = RdEntry::class;

    protected static ?string $navigationGroup = 'Sales & Bonds';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'RD Collections';

    public static function canCreate(): bool
    {
        return false;   // collections are recorded through the RD Collection Entry page
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('paid_on')->label('Date')->date()->sortable(),
                Tables\Columns\TextColumn::make('bond_id')->label('Bond')->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable()
                    ->description(fn (RdEntry $r) => $r->member?->member_code),
                Tables\Columns\TextColumn::make('due_count')->label('Due #')->badge()->sortable(),
                Tables\Columns\TextColumn::make('value')->label('Amount')->baseMoney()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->baseMoney()->label('Total collected')),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->label('Recorded')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('paid_on')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRdEntries::route('/'),
        ];
    }
}
