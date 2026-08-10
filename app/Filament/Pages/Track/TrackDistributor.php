<?php

namespace App\Filament\Pages\Track;

use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\RdEntry;
use App\Models\RedeemableQr;
use App\Models\SalesInvoice;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Facades\DB;

/**
 * Track Distributor — one code/phone in, the member's whole story out: profile,
 * genealogy position, wallet, contracts, RD history, QRs, earnings and purchases.
 */
class TrackDistributor extends TrackPage
{
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Track Distributor';

    protected static ?string $title = 'Track Distributor';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('query')
                    ->label('Distributor code / phone')
                    ->placeholder('LJW01 or 98xxxxxx00')
                    ->required(),
            ]),
        ])->statePath('data');
    }

    public function lookup(): void
    {
        $q = trim((string) ($this->form->getState()['query'] ?? ''));
        $this->sections = [];
        $this->searched = true;

        $member = Member::query()
            ->when(static::dealerBranchId(), fn ($qq, $b) => $qq->where('branch_id', $b))
            ->where(fn ($w) => $w->where('member_code', $q)
                ->orWhere('phone', $q)
                ->orWhere('member_code', 'like', "%{$q}%"))
            ->with(['rank', 'branch', 'referrer', 'upline', 'wallet'])
            ->first();

        if (! $member) {
            return;
        }

        $this->sections[] = ['heading' => __('Profile'), 'kv' => [
            __('Member code') => $member->member_code,
            __('Name') => $member->name,
            __('Phone') => trim(($member->phone_country_code ?? '') . ' ' . $member->phone),
            __('Status') => ucfirst((string) $member->status),
            __('Joined on') => $this->dmy($member->joined_on),
            __('Stage') => Translatable::pick($member->rank?->name),
            __('Branch') => $member->branch?->name,
            __('Placement') => ucfirst((string) $member->placement),
            __('Sponsor') => $member->referrer ? "{$member->referrer->member_code} — {$member->referrer->name}" : null,
            __('Upline') => $member->upline ? "{$member->upline->member_code} — {$member->upline->name}" : null,
            __('BV') => $this->money($member->bv),
            __('Group BV') => $this->money($member->gbv),
            __('Downlines') => (string) $member->downline_count,
        ]];

        if ($w = $member->wallet) {
            $this->sections[] = ['heading' => __('Wallet'), 'kv' => [
                __('Cash balance') => $this->money($w->cash_balance),
                __('Coupon balance') => $this->money($w->coupon_balance),
                __('E-pin balance') => $this->money($w->epin_balance),
                __('Digi gold (g)') => number_format((float) $w->digi_gold_grams, 4),
                __('Total earned') => $this->money($w->earning_total),
                __('Total withdrawn') => $this->money($w->withdrawn_total),
            ]];
        }

        $contracts = \App\Models\MemberContract::with('plan')
            ->where('member_id', $member->id)->latest('start_date')->limit(15)->get();
        $this->sections[] = ['heading' => __('Contracts'),
            'columns' => [__('Contract #'), __('Scheme'), __('Amount'), __('Start'), __('End'), __('Status')],
            'rows' => $contracts->map(fn ($c) => [
                $c->contract_no, $c->plan?->code, $this->money($c->amount),
                $this->dmy($c->start_date), $this->dmy($c->end_date), ucfirst((string) $c->status),
            ])->all()];

        $rd = RdEntry::where('member_id', $member->id)->latest('paid_on')->limit(10)->get();
        $this->sections[] = ['heading' => __('Recent RD collections'),
            'columns' => [__('Paid on'), __('Amount'), __('Instalment #'), __('Bond')],
            'rows' => $rd->map(fn ($r) => [
                $this->dmy($r->paid_on), $this->money($r->value), (string) $r->due_count, (string) $r->bond_id,
            ])->all()];

        $qrs = RedeemableQr::where('member_id', $member->id)->latest()->limit(10)->get();
        $this->sections[] = ['heading' => __('Redeemable QRs'),
            'columns' => [__('QR code'), __('Mode'), __('Gram worth'), __('Cash worth'), __('Status'), __('Sent')],
            'rows' => $qrs->map(fn ($r) => [
                $r->qr_code, strtoupper((string) $r->qr_mode), number_format((float) $r->gram_worth, 4),
                $this->money($r->cash_worth), ucfirst((string) $r->status), $r->qr_sent ? __('Yes') : __('No'),
            ])->all()];

        $streams = CommissionLedger::where('member_id', $member->id)
            ->select('type',
                DB::raw('COUNT(*) c'), DB::raw('SUM(amount) gross'), DB::raw('SUM(tds) tds'),
                DB::raw('SUM(service_charge) svc'), DB::raw('SUM(COALESCE(net_amount, 0)) net'))
            ->groupBy('type')->get();
        $cbc = CbcEntry::where('member_id', $member->id)
            ->select(DB::raw('COUNT(*) c'), DB::raw('SUM(worth) gross'))->first();
        $rows = $streams->map(fn ($s) => [
            $s->type, (string) $s->c, $this->money($s->gross), $this->money($s->tds),
            $this->money($s->svc), $this->money($s->net),
        ])->all();
        if ($cbc && $cbc->c > 0) {
            $rows[] = ['CBC', (string) $cbc->c, $this->money($cbc->gross), '—', '—', '—'];
        }
        $this->sections[] = ['heading' => __('Earnings summary'),
            'columns' => [__('Stream'), __('Entries'), __('Gross'), __('TDS'), __('Service'), __('Net paid')],
            'rows' => $rows];

        $sales = SalesInvoice::where('customer_member_id', $member->id)->latest('date')->limit(10)->get();
        $this->sections[] = ['heading' => __('Recent purchases (sales invoices)'),
            'columns' => [__('Invoice #'), __('Date'), __('Grand total'), __('Payment')],
            'rows' => $sales->map(fn ($s) => [
                $s->invoice_no, $this->dmy($s->date), $this->money($s->grand_total), strtoupper((string) $s->payment_type),
            ])->all()];
    }
}
