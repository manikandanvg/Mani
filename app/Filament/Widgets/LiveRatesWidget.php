<?php

namespace App\Filament\Widgets;

use App\Models\LiveRate;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Live gold / silver / diamond price display (per gram) for the base country.
 * Rates are admin-controllable and country-specific (see LiveRateResource).
 */
class LiveRatesWidget extends BaseWidget
{
    protected static ?int $sort = -3; // show near the top

    /** Rates mean nothing to the support desk — their dashboard is tickets only. */
    public static function canView(): bool
    {
        return ! (auth()->user()?->isSupport() ?? true);
    }

    protected function getStats(): array
    {
        $rate = LiveRate::latestFor('IN');

        if (! $rate) {
            return [Stat::make('Live Rates', 'Not set')->description('Add a rate under System → Live Rates')];
        }

        $when = $rate->effective_at?->diffForHumans() ?? '';
        $tag = $rate->is_override ? 'admin override' : strtoupper($rate->source);

        return [
            Stat::make('Gold / g', Money::display((float) $rate->gold))
                ->description("{$tag} · {$when}")->descriptionIcon('heroicon-m-sparkles')->color('warning'),
            Stat::make('Silver / g', Money::display((float) $rate->silver))
                ->description($rate->country)->descriptionIcon('heroicon-m-sparkles')->color('gray'),
            Stat::make('Diamond / g', Money::display((float) $rate->diamond))
                ->description($rate->country)->descriptionIcon('heroicon-m-sparkles')->color('info'),
        ];
    }
}
