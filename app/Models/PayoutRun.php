<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One monthly cut-off run (docs/CUTOFF-PLAN.md). */
class PayoutRun extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'commission';

    protected $guarded = [];

    protected $casts = [
        'period' => 'date',
        'cutoff_date' => 'date',
        'gap_gross' => 'decimal:2',
        'cbc_worth' => 'decimal:2',
        'options' => 'array',
        'approved_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function gapRows() { return $this->hasMany(CommissionLedger::class, 'payout_run_id'); }
    public function cbcRows() { return $this->hasMany(CbcEntry::class, 'payout_run_id'); }

    /** @var array<string,float>|null */
    protected ?array $cbcSplitCache = null;

    /**
     * Plan-aware split of this run's coupons (legacy month report: member plans 50 % cash,
     * dealership plans 100 % cash TDS only) — see CommissionApprovalService::cbcSplitTotals().
     *
     * @return array{cash: float, tds: float, service: float, coupon: float, worth: float, dealer_worth: float, member_worth: float}
     */
    public function cbcSplit(): array
    {
        return $this->cbcSplitCache ??= \App\Services\CommissionApprovalService::cbcSplitTotals(
            CbcEntry::query()->where('payout_run_id', $this->id)
        );
    }
    public function runBy() { return $this->belongsTo(User::class, 'run_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }

    /** "Nov 2026" — the pay month (legacy remarks column). */
    public function label(): string
    {
        return $this->period?->format('M Y') ?? '—';
    }

    /**
     * Per-member summary of the run — the shape of legacy tbl_gap_log: rows, gross,
     * TDS, service charge (slab-aware, Commission Setup), net.
     *
     * @return array<int, array{member_id:int, member_code:string, name:string, rows:int, gross:float, tds:float, service:float, net:float, status:string}>
     */
    public function memberSummary(): array
    {
        $rows = $this->gapRows()
            ->selectRaw('member_id, COUNT(*) AS n, SUM(amount) AS gross, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS paid_n', ['paid'])
            ->groupBy('member_id')
            ->with('member:id,member_code,name')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $gross = round((float) $r->gross, 2);
            [$tdsPct, $svcPct] = \App\Services\CommissionApprovalService::chargesFor('GAP', $gross);
            $tds = round($gross * $tdsPct / 100, 2);
            $svc = round($gross * $svcPct / 100, 2);
            $out[] = [
                'member_id' => (int) $r->member_id,
                'member_code' => (string) ($r->member?->member_code ?? $r->member_id),
                'name' => (string) ($r->member?->name ?? ''),
                'rows' => (int) $r->n,
                'gross' => $gross,
                'tds' => $tds,
                'service' => $svc,
                'net' => round($gross - $tds - $svc, 2),
                'status' => (int) $r->paid_n >= (int) $r->n ? 'paid' : ((int) $r->paid_n > 0 ? 'partly paid' : 'pending'),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['member_code'], $b['member_code']));

        return $out;
    }
}
