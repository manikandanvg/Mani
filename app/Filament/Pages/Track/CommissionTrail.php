<?php

namespace App\Filament\Pages\Track;

use App\Models\Branch;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\ResellerCommission;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * One distributor's commissions of EVERY kind, for the Track screens (board 2026-11-15):
 * what was booked, what is paid, what is still pending — with the date of each transaction
 * and the branch / source it came from.
 *
 *   IC             commission_ledger type IC
 *   Level income   commission_ledger type GAP
 *   CBC            cbc_entries (a coupon dated in the future is "scheduled", not yet due)
 *   Margins        reseller_commissions the member earns as a dealer: bill, gold, silver,
 *                  stock-transfer and Gold Saving renewal margin
 *
 * Paid = approved through Commission Approval and credited. Pending = booked, awaiting it.
 */
class CommissionTrail
{
    public const MARGIN_LABELS = [
        ResellerCommission::COM_BILL_MARGIN => 'Bill margin',
        ResellerCommission::COM_GOLD_MARGIN => 'Gold margin',
        ResellerCommission::COM_SILVER_MARGIN => 'Silver margin',
        ResellerCommission::COM_STOCK_TRANSFER_MARGIN => 'Stock transfer margin',
        ResellerCommission::COM_RD_RENEWAL_MARGIN => 'Gold Saving renewal margin',
    ];

    protected static function m($v): string
    {
        return Money::group((float) $v);
    }

    protected static function dmy($d): string
    {
        return $d ? Carbon::parse($d)->format('d M Y') : '—';
    }

    protected static function range($query, string $column, ?string $from, ?string $to)
    {
        return $query
            ->when($from, fn ($q, $v) => $q->whereDate($column, '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate($column, '<=', $v));
    }

    /**
     * Booked / paid / pending per commission type.
     *
     * @return array<int, array{type: string, entries: int, booked: float, paid: float, pending: float, scheduled: float, tds: float, service: float, net: float}>
     */
    public static function summary(Member $member, ?string $from = null, ?string $to = null): array
    {
        $today = now()->toDateString();
        $rows = [];

        foreach (['IC' => 'IC', 'GAP' => 'Level income'] as $type => $label) {
            $s = static::range(CommissionLedger::where('member_id', $member->id)->where('type', $type), 'earned_on', $from, $to)
                ->selectRaw("COUNT(*) c, COALESCE(SUM(amount),0) booked, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END),0) paid,"
                    . ' COALESCE(SUM(tds),0) tds, COALESCE(SUM(service_charge),0) svc, COALESCE(SUM(COALESCE(net_amount,0)),0) net')->first();
            $rows[] = ['type' => $label, 'entries' => (int) $s->c, 'booked' => (float) $s->booked, 'paid' => (float) $s->paid,
                'pending' => (float) $s->booked - (float) $s->paid, 'scheduled' => 0.0,
                'tds' => (float) $s->tds, 'service' => (float) $s->svc, 'net' => (float) $s->net];
        }

        $s = static::range(CbcEntry::where('member_id', $member->id), 'cbc_date', $from, $to)
            ->selectRaw("COUNT(*) c, COALESCE(SUM(CASE WHEN cbc_date <= ? OR status = 'paid' THEN worth ELSE 0 END),0) booked,"
                . " COALESCE(SUM(CASE WHEN status = 'paid' THEN worth ELSE 0 END),0) paid,"
                . " COALESCE(SUM(CASE WHEN cbc_date > ? AND status <> 'paid' THEN worth ELSE 0 END),0) scheduled,"
                . ' COALESCE(SUM(tds),0) tds, COALESCE(SUM(service_charge),0) svc, COALESCE(SUM(COALESCE(cash_amount,0)),0) net', [$today, $today])->first();
        $rows[] = ['type' => 'CBC', 'entries' => (int) $s->c, 'booked' => (float) $s->booked, 'paid' => (float) $s->paid,
            'pending' => (float) $s->booked - (float) $s->paid, 'scheduled' => (float) $s->scheduled,
            'tds' => (float) $s->tds, 'service' => (float) $s->svc, 'net' => (float) $s->net];

        $margins = static::range(ResellerCommission::earnedBy($member), 'bill_date', $from, $to)
            ->selectRaw("com_type_id, COUNT(*) c, COALESCE(SUM(com_value),0) booked, COALESCE(SUM(CASE WHEN status = 'paid' THEN com_value ELSE 0 END),0) paid,"
                . ' COALESCE(SUM(tds),0) tds, COALESCE(SUM(service_charge),0) svc, COALESCE(SUM(COALESCE(net_amount,0)),0) net')
            ->groupBy('com_type_id')->get()->keyBy('com_type_id');
        foreach (self::MARGIN_LABELS as $id => $label) {
            $s = $margins[$id] ?? null;
            if (! $s && $id !== ResellerCommission::COM_BILL_MARGIN) {
                continue;   // only list the margins this dealer actually has (bill margin always)
            }
            $rows[] = ['type' => $label, 'entries' => (int) ($s->c ?? 0), 'booked' => (float) ($s->booked ?? 0), 'paid' => (float) ($s->paid ?? 0),
                'pending' => (float) ($s->booked ?? 0) - (float) ($s->paid ?? 0), 'scheduled' => 0.0,
                'tds' => (float) ($s->tds ?? 0), 'service' => (float) ($s->svc ?? 0), 'net' => (float) ($s->net ?? 0)];
        }

        return $rows;
    }

    /** The summary as a Track section (with a footer total). */
    public static function summarySection(Member $member, ?string $from = null, ?string $to = null, bool $deductions = false): array
    {
        $rows = static::summary($member, $from, $to);
        $sum = fn (string $k) => array_sum(array_column($rows, $k));

        $columns = [__('Commission'), __('Entries'), __('Booked'), __('Paid'), __('Pending')];
        $extra = $deductions ? [__('TDS'), __('Service charge'), __('Net credited')] : [];
        $columns = array_merge($columns, $extra, [__('Scheduled (future CBC)')]);

        $line = fn (array $r) => array_merge(
            [__($r['type']), (string) $r['entries'], static::m($r['booked']), static::m($r['paid']), static::m($r['pending'])],
            $deductions ? [static::m($r['tds']), static::m($r['service']), static::m($r['net'])] : [],
            [$r['scheduled'] > 0 ? static::m($r['scheduled']) : '—'],
        );

        return [
            'heading' => __('Commissions — booked, paid and pending'),
            'columns' => $columns,
            'rows' => array_map($line, $rows),
            'totals' => array_merge(
                [__('Total'), (string) $sum('entries'), static::m($sum('booked')), static::m($sum('paid')), static::m($sum('pending'))],
                $deductions ? [static::m($sum('tds')), static::m($sum('service')), static::m($sum('net'))] : [],
                [static::m($sum('scheduled'))],
            ),
        ];
    }

    /**
     * Every commission transaction, newest first: date, type, where it came from (the earning
     * member / contract and the branch), gross, net, status and the date it was paid.
     */
    public static function transactionsSection(Member $member, ?string $from = null, ?string $to = null, int $perType = 150): array
    {
        $today = now()->toDateString();
        $out = collect();

        $ledger = static::range(CommissionLedger::where('member_id', $member->id), 'earned_on', $from, $to)
            ->with('fromMember')->latest('earned_on')->latest('id')->limit($perType * 2)->get();
        $cbc = static::range(CbcEntry::where('member_id', $member->id), 'cbc_date', $from, $to)
            // without a date range: everything due so far plus the next coupon or two, not 24 future months
            ->when(! $to, fn ($q) => $q->whereDate('cbc_date', '<=', now()->addMonth()->toDateString()))
            ->with('bond')->latest('cbc_date')->limit($perType)->get();
        $margins = static::range(ResellerCommission::earnedBy($member), 'bill_date', $from, $to)
            ->latest('bill_date')->latest('id')->limit($perType)->get();

        $branches = Branch::whereIn('id', $ledger->pluck('branch_id')
            ->merge($cbc->pluck('bond.branch_id'))->merge($margins->pluck('branch_id'))->filter()->unique())
            ->get()->keyBy('id');
        $branch = fn ($id) => $id && isset($branches[$id]) ? $branches[$id]->name . ($branches[$id]->city ? ' · ' . $branches[$id]->city : '') : '—';

        foreach ($ledger as $r) {
            $out->push([
                'sort' => (string) $r->earned_on?->toDateString(),
                'row' => [
                    static::dmy($r->earned_on), $r->type === 'GAP' ? __('Level income') : 'IC',
                    trim(($r->fromMember ? $r->fromMember->member_code . ' — ' . $r->fromMember->name : '')
                        . ($r->level !== null ? ' · L' . $r->level : '') . ($r->invoice_no ? ' · ' . $r->invoice_no : ''), ' ·') ?: '—',
                    $branch($r->branch_id), static::m($r->amount), static::m($r->net_amount),
                    $r->status === 'paid' ? __('Paid') : ucfirst((string) $r->status), static::dmy($r->paid_on),
                ],
            ]);
        }
        foreach ($cbc as $r) {
            $future = $r->status !== 'paid' && (string) $r->cbc_date > $today;
            $out->push([
                'sort' => (string) Carbon::parse($r->cbc_date)->toDateString(),
                'row' => [
                    static::dmy($r->cbc_date), 'CBC',
                    trim(($r->bond?->invoice_no ? __('Contract') . ' ' . $r->bond->invoice_no : __('Bond') . ' #' . $r->bond_id) . ($r->code ? ' · ' . $r->code : '')),
                    $branch($r->bond?->branch_id), static::m($r->worth), static::m($r->cash_amount),
                    $r->status === 'paid' ? __('Paid') : ($future ? __('Scheduled') : __('Pending')), static::dmy($r->paid_on),
                ],
            ]);
        }
        foreach ($margins as $r) {
            $out->push([
                'sort' => (string) Carbon::parse($r->bill_date)->toDateString(),
                'row' => [
                    static::dmy($r->bill_date), __(self::MARGIN_LABELS[$r->com_type_id] ?? 'Margin'),
                    $r->invoice_no ? __('Bill') . ' ' . $r->invoice_no : '—',
                    $branch($r->branch_id), static::m($r->com_value), static::m($r->net_amount),
                    $r->status === 'paid' ? __('Paid') : __('Pending'), static::dmy($r->paid_on),
                ],
            ]);
        }

        return [
            'heading' => __('Commission transactions'),
            'columns' => [__('Date'), __('Commission'), __('From / reference'), __('Branch (source)'), __('Gross'), __('Net'), __('Status'), __('Paid on')],
            'rows' => $out->sortByDesc('sort')->pluck('row')->values()->all(),
        ];
    }
}
