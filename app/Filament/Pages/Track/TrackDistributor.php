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
            // Board 2026-11-01: no EPIN wallet in the new system; the coupon wallet (CBC remainder)
            // is a Head Office reference figure only — shown to the super admin, never to dealers.
            $this->sections[] = ['heading' => __('Wallet'), 'kv' => array_filter([
                __('Cash balance') => $this->money($w->cash_balance),
                __('Coupon wallet (HQ reference)') => auth()->user()?->isSuperAdmin() ? $this->money($w->coupon_balance) : null,
                __('Digi gold (g)') => number_format((float) $w->digi_gold_grams, 4),
                __('Digi silver (g)') => number_format((float) $w->digi_silver_grams, 4),
                __('Total earned') => $this->money($w->earning_total),
                __('Total withdrawn') => $this->money($w->withdrawn_total),
            ], fn ($v) => $v !== null)];
        }

        $contracts = \App\Models\MemberContract::with(['plan', 'branch'])
            ->where('member_id', $member->id)->latest('start_date')->limit(15)->get();
        $this->sections[] = ['heading' => __('Contracts'),
            'columns' => [__('Contract #'), __('Scheme'), __('Amount'), __('Start'), __('End'), __('Status'), __('Branch')],
            'rows' => $contracts->map(fn ($c) => [
                $c->contract_no, trim($c->plan?->code . ' — ' . (Translatable::pick($c->plan?->name) ?? '')), $this->money($c->amount),
                $this->dmy($c->start_date), $this->dmy($c->end_date), ucfirst((string) $c->status), $c->branch?->name,
            ])->all()];

        $rd = RdEntry::with('branch')->where('member_id', $member->id)->latest('paid_on')->limit(10)->get();
        $this->sections[] = ['heading' => __('Recent Gold Saving collections'),
            'columns' => [__('Paid on'), __('Amount'), __('Instalment #'), __('Bond'), __('Collected at')],
            'rows' => $rd->map(fn ($r) => [
                $this->dmy($r->paid_on), $this->money($r->value), (string) $r->due_count, (string) $r->bond_id, $r->branch?->name,
            ])->all()];

        $qrs = RedeemableQr::with(['branch', 'redeemBranch'])->where('member_id', $member->id)->latest()->limit(15)->get();
        $this->sections[] = ['heading' => __('Redeemable QRs'),
            'columns' => [__('QR code'), __('Type'), __('Metal'), __('Gram worth'), __('Cash worth'), __('Status'), __('Generated at'), __('Redeemed at')],
            'rows' => $qrs->map(fn ($r) => [
                $r->qr_code, TrackQr::kindLabel($r), strtoupper((string) $r->qr_mode), number_format((float) $r->gram_worth, 4),
                $this->money($r->cash_worth), ucfirst((string) $r->status),
                trim(($r->branch?->name ?? '—') . ' · ' . $this->dmy($r->created_at)),
                $r->redeemed_at ? trim(($r->redeemBranch?->name ?? '—') . ' · ' . $r->redeemed_at->format('d M Y')) : null,
            ])->all()];

        // Board 2026-11-15: every kind of commission — IC, CBC, level income, margins — booked,
        // paid and pending, then each transaction with its date and the branch it came from.
        $this->sections[] = CommissionTrail::summarySection($member);
        $this->sections[] = CommissionTrail::transactionsSection($member, null, null, 100);

        $sales = SalesInvoice::with('branch')->where('customer_member_id', $member->id)->latest('date')->limit(10)->get();
        $this->sections[] = ['heading' => __('Recent purchases (sales invoices)'),
            'columns' => [__('Invoice #'), __('Date'), __('Billed at'), __('Grand total'), __('Payment')],
            'rows' => $sales->map(fn ($s) => [
                $s->invoice_no, $this->dmy($s->date), $s->branch?->name, $this->money($s->grand_total), strtoupper((string) $s->payment_type),
            ])->all()];
    }
}
