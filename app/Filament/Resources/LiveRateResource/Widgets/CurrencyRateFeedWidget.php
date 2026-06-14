<?php

namespace App\Filament\Resources\LiveRateResource\Widgets;

use App\Models\Currency;
use App\Models\LiveRate;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Currency rate feed — every ACTIVE currency auto-appears here with its gold/silver/
 * diamond per-gram rate derived from the latest base rate × that currency's FX
 * (rate_to_base). Add a currency under System → Currencies and it shows up instantly;
 * no manual rate entry per currency. The base rate is set under Live Rates.
 */
class CurrencyRateFeedWidget extends BaseWidget
{
    protected static ?string $heading = 'Currency rate feed (auto · via exchange rates)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $rate = LiveRate::latestFor('IN');
        $gold = (float) ($rate->gold ?? 0);
        $silver = (float) ($rate->silver ?? 0);
        $diamond = (float) ($rate->diamond ?? 0);

        return $table
            ->query(Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code'))
            ->paginated(false)
            ->emptyStateHeading('No active currencies yet')
            ->emptyStateDescription('Add a currency under System → Currencies and it will appear here automatically.')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Currency')->badge()
                    ->description(fn (Currency $c) => $c->name),
                Tables\Columns\TextColumn::make('rate_to_base')->label('Exchange rate')
                    ->formatStateUsing(fn ($state, Currency $c) => $c->is_base
                        ? 'Base'
                        : '1 ' . (Money::base()?->code ?? 'INR') . ' = ' . rtrim(rtrim(number_format((float) $state, 6), '0'), '.') . ' ' . $c->code),
                Tables\Columns\TextColumn::make('gold')->label('Gold / g')->weight('bold')->color('warning')
                    ->state(fn (Currency $c) => Money::format($gold, $c->code)),
                Tables\Columns\TextColumn::make('silver')->label('Silver / g')->color('gray')
                    ->state(fn (Currency $c) => Money::format($silver, $c->code)),
                Tables\Columns\TextColumn::make('diamond')->label('Diamond / g')->color('info')
                    ->state(fn (Currency $c) => Money::format($diamond, $c->code)),
                Tables\Columns\IconColumn::make('is_base')->label('Base')->boolean(),
            ]);
    }
}
