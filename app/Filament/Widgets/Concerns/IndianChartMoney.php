<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\Money;
use Filament\Support\RawJs;

/**
 * Chart amounts the Indian way (board 2026-11-15): the hover popup and the axis read
 * ₹1,23,45,678.00 — lakh / crore grouping — instead of Chart.js's 12,345,678. A viewer on
 * another display currency gets that currency's symbol with ordinary grouping.
 */
trait IndianChartMoney
{
    protected function getOptions(): array|RawJs|null
    {
        $code = strtoupper((string) Money::current());
        // SINGLE-quoted JS strings only: Filament prints these options inside a double-quoted
        // HTML attribute, so one double quote here cuts the attribute short and the chart stays empty.
        $symbol = "'" . preg_replace('/[^\p{L}\p{Sc}. ]/u', '', (string) (Money::currency($code)?->symbol ?? '')) . "'";
        $locale = $code === 'INR' ? "'en-IN'" : "'en-US'";

        return RawJs::make(<<<JS
            {
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var name = (ctx.dataset.label || '').replace(/\\s*\\(.*\\)\\s*$/, '');
                                return name + ': ' + {$symbol} + Number(ctx.parsed.y || 0).toLocaleString({$locale}, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            },
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return {$symbol} + Number(value).toLocaleString({$locale}, { maximumFractionDigits: 0 });
                            },
                        },
                    },
                },
            }
        JS);
    }
}
