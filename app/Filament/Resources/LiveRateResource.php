<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\LiveRateResource\Pages;
use App\Filament\Resources\LiveRateResource\RelationManagers;
use App\Models\LiveRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LiveRateResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = LiveRate::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Live Rates';

    protected static ?string $navigationIcon = 'heroicon-o-currency-rupee';

    /**
     * Board 2026-10-31 (20): every rate change under System → Live Rates pushes a "price
     * for today" message to all app-registered members, stating the new per-gram prices
     * and how far they moved against the previous board rate.
     *
     * @param  array|null  $previous  ['gold' => float, 'silver' => float] of the rate being replaced
     * @return int members notified
     */
    public static function announce(LiveRate $rate, ?array $previous): int
    {
        $line = function (string $metal, float $now) use ($previous): string {
            $txt = ucfirst($metal) . ' ₹' . \App\Support\Money::group($now) . '/g';
            $was = isset($previous[$metal]) ? (float) $previous[$metal] : null;
            if ($was === null || $was <= 0) {
                return $txt;
            }
            $diff = round($now - $was, 2);
            if (abs($diff) < 0.005) {
                return $txt . ' (unchanged)';
            }

            return $txt . ' (' . ($diff > 0 ? '▲ up ₹' : '▼ down ₹') . \App\Support\Money::group(abs($diff))
                . ' from ₹' . \App\Support\Money::group($was) . ')';
        };

        $effective = $rate->effective_at ? \Illuminate\Support\Carbon::parse($rate->effective_at)->timezone(config('app.timezone')) : now();
        $body = "Today's price at Lord Jeweller — " . $line('gold', (float) $rate->gold) . ' · ' . $line('silver', (float) $rate->silver)
            . '. Effective ' . $effective->format('d M Y, h:i A') . '. Buy, save or redeem at this rate from your app or nearest branch.';

        return \App\Services\Push\Notifier::broadcast('rates', "Today's gold & silver price", $body, route: '/rates');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Metal rates (per gram)')->columns(2)->schema([
                    Forms\Components\TextInput::make('country')->required()->maxLength(2)->default('IN')
                        ->helperText('ISO country code — rates vary by country.'),
                    Forms\Components\Select::make('currency_code')
                        ->label('Quoted in currency')
                        ->options(fn () => \App\Models\Currency::orderBy('code')->pluck('code', 'code'))
                        ->default('INR')->required()->native(false)
                        ->helperText('Currency these per-gram prices are stated in (e.g. London gold in EUR).'),
                    Forms\Components\Select::make('source')
                        ->options(['api' => 'API (fetched)', 'manual' => 'Manual (admin)'])
                        ->default('manual')->required(),
                    Forms\Components\TextInput::make('gold')->label('Gold /g')->required()->numeric()->default(0),
                    Forms\Components\TextInput::make('silver')->label('Silver /g')->required()->numeric()->default(0),
                    Forms\Components\TextInput::make('diamond')->label('Diamond /g')->required()->numeric()->default(0),
                    Forms\Components\Toggle::make('is_override')->label('Admin override')->default(true)
                        ->helperText('Marks this as an admin-set price (overrides the API).'),
                    Forms\Components\DateTimePicker::make('effective_at')->default(now())->required(),
                    Forms\Components\Hidden::make('updated_by')->default(fn () => auth()->id()),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('country')->badge()->sortable(),
                Tables\Columns\TextColumn::make('gold')->label('Gold /g')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('silver')->label('Silver /g')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('diamond')->label('Diamond /g')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('source')->badge()
                    ->color(fn ($state) => $state === 'api' ? 'info' : 'warning'),
                Tables\Columns\IconColumn::make('is_override')->boolean()->label('Override'),
                Tables\Columns\TextColumn::make('updatedBy.name')->label('By')->toggleable(),
                Tables\Columns\TextColumn::make('effective_at')->dateTime()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLiveRates::route('/'),
            'create' => Pages\CreateLiveRate::route('/create'),
            'edit' => Pages\EditLiveRate::route('/{record}/edit'),
        ];
    }
}
