<?php

namespace App\Filament\Widgets;

use App\Models\ResellerCommission;
use App\Support\Money;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HQ dashboard — commissions month by month, BOOKED against DISBURSED (board 2026-11-15):
 *
 *   IC            commission_ledger type IC   booked on earned_on · disbursed on paid_on
 *   Level         commission_ledger type GAP  (the monthly level income)
 *   CBC           cbc_entries                 booked on the coupon date (never a future one)
 *   Bill margin   reseller_commissions        booked on the bill date (payable rows only —
 *                                             the old system paid everything up to 22-10-2026)
 *
 * "Booked" is the gross amount earned in the month; "disbursed" is the gross of the rows the
 * Commission Approval marked paid in the month. The legend toggles any line on or off.
 */
class CommissionFlowChart extends ChartWidget
{
    use Concerns\IndianChartMoney;

    protected static ?string $heading = 'Commissions — booked vs disbursed';

    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '380px';

    public ?string $filter = '6';

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u !== null && ! $u->isDistributor() && ! $u->isSupport();
    }

    protected function getFilters(): ?array
    {
        return ['3' => 'Last 3 months', '6' => 'Last 6 months', '12' => 'Last 12 months'];
    }

    public function getDescription(): ?string
    {
        return __('All branches · gross per month · click a legend entry to hide / show its line');
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** [Y-m => sum] of $sumColumn grouped by the month of $dateColumn. */
    protected static function monthly($query, string $dateColumn, string $sumColumn, string $from): array
    {
        $month = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$dateColumn})"
            : "DATE_FORMAT({$dateColumn}, '%Y-%m')";

        return $query->whereNotNull($dateColumn)->whereDate($dateColumn, '>=', $from)
            ->selectRaw("{$month} as ym, SUM({$sumColumn}) as total")
            ->groupBy('ym')->pluck('total', 'ym')->map(fn ($v) => (float) $v)->all();
    }

    protected function getData(): array
    {
        $months = in_array((int) $this->filter, [3, 6, 12], true) ? (int) $this->filter : 6;
        $currency = Money::current();

        $series = Cache::remember("hq-commission-flow:{$months}:" . now()->format('Y-m-d-H'), 900, function () use ($months) {
            $from = now()->subMonthsNoOverflow($months - 1)->startOfMonth()->toDateString();
            $today = now()->toDateString();
            $ledger = fn (string $type) => DB::table('commission_ledger')->where('type', $type);
            $cbc = fn () => DB::table('cbc_entries');
            $margin = fn () => ResellerCommission::query()->where('com_type_id', ResellerCommission::COM_BILL_MARGIN)->payable()->toBase();

            return [
                'IC booked' => static::monthly($ledger('IC'), 'earned_on', 'amount', $from),
                'IC disbursed' => static::monthly($ledger('IC')->where('status', 'paid'), 'paid_on', 'amount', $from),
                'CBC booked' => static::monthly($cbc()->whereDate('cbc_date', '<=', $today), 'cbc_date', 'worth', $from),
                'CBC disbursed' => static::monthly($cbc()->where('status', 'paid'), 'paid_on', 'worth', $from),
                'Level booked' => static::monthly($ledger('GAP'), 'earned_on', 'amount', $from),
                'Level disbursed' => static::monthly($ledger('GAP')->where('status', 'paid'), 'paid_on', 'amount', $from),
                'Bill margin booked' => static::monthly($margin(), 'bill_date', 'com_value', $from),
                'Bill margin disbursed' => static::monthly($margin()->where('status', 'paid'), 'paid_on', 'com_value', $from),
            ];
        });

        $keys = [];
        $labels = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->subMonthsNoOverflow($i)->startOfMonth();
            $keys[] = $m->format('Y-m');
            $labels[] = $m->format('M Y');
        }

        // one colour per stream; booked = solid, disbursed = dashed
        $colours = ['IC' => '#ab222f', 'CBC' => '#e6ad46', 'Level' => '#2563eb', 'Bill margin' => '#059669'];
        $datasets = [];
        foreach ($series as $label => $byMonth) {
            $stream = trim(str_replace(['booked', 'disbursed'], '', $label));
            $paid = str_contains($label, 'disbursed');
            $datasets[] = [
                'label' => __($label),
                'data' => array_map(fn ($k) => round(Money::convert((float) ($byMonth[$k] ?? 0), $currency), 2), $keys),
                'borderColor' => $colours[$stream] ?? '#6b7280',
                'backgroundColor' => $colours[$stream] ?? '#6b7280',
                'borderDash' => $paid ? [6, 4] : [],
                'borderWidth' => 2,
                'pointRadius' => 3,
                'tension' => 0.25,
                'fill' => false,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }
}
