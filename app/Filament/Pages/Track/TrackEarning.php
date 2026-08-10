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
            ->first();

        if (! $member) {
            return;
        }

        $range = fn ($query, string $col) => $query
            ->when($d['from'] ?? null, fn ($qq, $v) => $qq->whereDate($col, '>=', $v))
            ->when($d['to'] ?? null, fn ($qq, $v) => $qq->whereDate($col, '<=', $v));

        $this->sections[] = ['heading' => __('Distributor'), 'kv' => [
            __('Member code') => $member->member_code,
            __('Name') => $member->name,
            __('Branch') => $member->branch?->name,
            __('Wallet cash balance') => $this->money($member->wallet?->cash_balance),
        ]];

        $streams = $range(CommissionLedger::where('member_id', $member->id), 'earned_on')
            ->select('type',
                DB::raw('COUNT(*) c'), DB::raw('SUM(amount) gross'),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) pending"),
                DB::raw("SUM(CASE WHEN status <> 'pending' THEN amount ELSE 0 END) settled"),
                DB::raw('SUM(tds) tds'), DB::raw('SUM(service_charge) svc'),
                DB::raw('SUM(COALESCE(net_amount, 0)) net'))
            ->groupBy('type')->get();
        $this->sections[] = ['heading' => __('Streams (IC / GAP ledger)'),
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

        $reseller = $range(ResellerCommission::where(fn ($w) => $w
            ->where('reference_member_id', $member->id)
            ->orWhere('mapped_uid', $member->member_code)), 'bill_date')
            ->latest('bill_date')->limit(15)->get();
        $this->sections[] = ['heading' => __('Bill margin (reseller commissions)'),
            'columns' => [__('Bill date'), __('Invoice #'), __('Amount'), __('Status')],
            'rows' => $reseller->map(fn ($r) => [
                $this->dmy($r->bill_date), $r->invoice_no, $this->money($r->com_value), ucfirst((string) $r->status),
            ])->all()];

        $recent = $range(CommissionLedger::where('member_id', $member->id), 'earned_on')
            ->with('fromMember')->latest('earned_on')->limit(20)->get();
        $this->sections[] = ['heading' => __('Recent ledger entries'),
            'columns' => [__('Earned on'), __('Stream'), __('Level'), __('From'), __('Gross'), __('TDS'), __('Service'), __('Net'), __('Status')],
            'rows' => $recent->map(fn ($r) => [
                $this->dmy($r->earned_on), $r->type, $r->level !== null ? (string) $r->level : '—',
                $r->fromMember?->member_code, $this->money($r->amount), $this->money($r->tds),
                $this->money($r->service_charge), $this->money($r->net_amount), ucfirst((string) $r->status),
            ])->all()];
    }
}
