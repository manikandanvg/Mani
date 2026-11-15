<?php

namespace App\Filament\Pages\Track;

use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\ResellerCommission;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Facades\DB;

/**
 * Track Earning — a distributor's earnings across every stream, with the TDS 5% +
 * service 5% deductions and pending-vs-paid split (commission approval gate).
 */
class TrackEarning extends TrackPage
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Track Earning';

    protected static ?string $title = 'Track Earning';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\TextInput::make('query')
                    ->label('Distributor code / phone')
                    ->required(),
                Forms\Components\DatePicker::make('from')->label('From')->native(false),
                Forms\Components\DatePicker::make('to')->label('To')->native(false),
            ]),
        ])->statePath('data');
    }

    public function lookup(): void
    {
        $d = $this->form->getState();
        $q = trim((string) ($d['query'] ?? ''));
        $this->sections = [];
        $this->searched = true;

        $member = Member::query()
            ->when(static::dealerBranchId(), fn ($qq, $b) => $qq->where('branch_id', $b))
            ->where(fn ($w) => $w->where('member_code', $q)->orWhere('phone', $q))
            ->first()
            ?? Member::query()
                ->when(static::dealerBranchId(), fn ($qq, $b) => $qq->where('branch_id', $b))
                ->where('member_code', 'like', "%{$q}%")->first();

        if (! $member) {
            return;
        }
        $member->loadMissing(['branch', 'wallet', 'rank']);

        $range = fn ($query, string $col) => $query
            ->when($d['from'] ?? null, fn ($qq, $v) => $qq->whereDate($col, '>=', $v))
            ->when($d['to'] ?? null, fn ($qq, $v) => $qq->whereDate($col, '<=', $v));

        $this->sections[] = ['heading' => __('Distributor'), 'kv' => [
            __('Member code') => $member->member_code,
            __('Name') => $member->name,
            __('Stage') => \App\Support\Translatable::pick($member->rank?->name),
            __('Branch') => $member->branch?->pickLabel(),
            __('Wallet cash balance') => $this->money($member->wallet?->cash_balance),
            __('Total earned (wallet)') => $this->money($member->wallet?->earning_total),
            __('Total withdrawn') => $this->money($member->wallet?->withdrawn_total),
        ]];

        // Board 2026-11-15: ALL commission types in one view — booked, paid (passed), pending,
        // TDS, service charge and net — then every transaction with date and source branch.
        $from = $d['from'] ?? null;
        $to = $d['to'] ?? null;
        $summary = CommissionTrail::summary($member, $from, $to);
        $tot = fn (string $k) => array_sum(array_column($summary, $k));
        $this->sections[] = ['heading' => __('Position'), 'kv' => [
            __('Total booked') => $this->money($tot('booked')),
            __('Paid (passed)') => $this->money($tot('paid')),
            __('Pending approval') => $this->money($tot('pending')),
            __('Scheduled (future CBC)') => $this->money($tot('scheduled')),
            __('TDS deducted') => $this->money($tot('tds')),
            __('Service charge') => $this->money($tot('service')),
            __('Net credited') => $this->money($tot('net')),
            __('Period') => ($from || $to) ? trim(($from ? $this->dmy($from) : '…') . ' → ' . ($to ? $this->dmy($to) : '…')) : __('All time'),
        ]];
        $this->sections[] = CommissionTrail::summarySection($member, $from, $to, true);
        $this->sections[] = CommissionTrail::transactionsSection($member, $from, $to, 300);

        $streams = $range(CommissionLedger::where('member_id', $member->id), 'earned_on')
            ->select('type',
                DB::raw('COUNT(*) c'), DB::raw('SUM(amount) gross'),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) pending"),
                DB::raw("SUM(CASE WHEN status <> 'pending' THEN amount ELSE 0 END) settled"),
                DB::raw('SUM(tds) tds'), DB::raw('SUM(service_charge) svc'),
                DB::raw('SUM(COALESCE(net_amount, 0)) net'))
            ->groupBy('type')->get();
        $this->sections[] = ['heading' => __('IC / Level ledger by status'),
            'columns' => [__('Stream'), __('Entries'), __('Gross'), __('Pending'), __('Approved'), __('TDS'), __('Service'), __('Net credited')],
            'rows' => $streams->map(fn ($s) => [
                $s->type, (string) $s->c, $this->money($s->gross), $this->money($s->pending),
                $this->money($s->settled), $this->money($s->tds), $this->money($s->svc), $this->money($s->net),
            ])->all()];

        $cbc = $range(CbcEntry::where('member_id', $member->id), 'cbc_date')
            ->select('status', DB::raw('COUNT(*) c'), DB::raw('SUM(worth) worth'))
            ->groupBy('status')->get();
        $this->sections[] = ['heading' => __('CBC (monthly cashback)'),
            'columns' => [__('Status'), __('Entries'), __('Worth')],
            'rows' => $cbc->map(fn ($s) => [ucfirst((string) $s->status), (string) $s->c, $this->money($s->worth)])->all()];

        // Wallet side: what actually left as withdrawals, and contract settlements received.
        $withdrawals = $range(\App\Models\WalletWithdrawal::where('member_id', $member->id), 'created_at')
            ->latest('id')->limit(50)->get();
        if ($withdrawals->isNotEmpty()) {
            $this->sections[] = ['heading' => __('Wallet withdrawals'),
                'columns' => [__('Requested'), __('Amount'), __('Status'), __('Branch')],
                'rows' => $withdrawals->map(fn ($w) => [
                    $this->dmy($w->created_at), $this->money($w->amount), ucfirst(str_replace('_', ' ', (string) $w->status)),
                    $w->branch_id ? \App\Models\Branch::find($w->branch_id)?->name : null,
                ])->all(),
                'totals' => [__('Total'), $this->money($withdrawals->sum('amount')), '', '']];
        }
    }
}
