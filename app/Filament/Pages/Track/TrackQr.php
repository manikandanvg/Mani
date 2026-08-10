<?php

namespace App\Filament\Pages\Track;

use App\Models\RedeemableQr;
use App\Models\RedemptionInvoice;
use Filament\Forms;
use Filament\Forms\Form;

/**
 * Track QR — a redeemable-stock QR code (or its source invoice number) in, the QR's
 * life out: worth, WhatsApp delivery, OTP, redemption branch and tax invoice.
 */
class TrackQr extends TrackPage
{
    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Track QR';

    protected static ?string $title = 'Track QR';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('query')
                    ->label('QR code / invoice #')
                    ->required(),
            ]),
        ])->statePath('data');
    }

    public function lookup(): void
    {
        $q = trim((string) ($this->form->getState()['query'] ?? ''));
        $this->sections = [];
        $this->searched = true;

        $base = RedeemableQr::query()
            ->when(static::dealerBranchId(), fn ($qq, $b) => $qq
                ->where(fn ($w) => $w->where('branch_id', $b)->orWhere('redeem_branch_id', $b)))
            ->with(['member', 'branch', 'redeemBranch']);

        $matches = (clone $base)->where('qr_code', $q)->get();
        if ($matches->isEmpty()) {
            $matches = $base->where('invoice_no', $q)->latest()->limit(25)->get();
        }

        if ($matches->isEmpty()) {
            return;
        }

        if ($matches->count() > 1) {
            $this->sections[] = ['heading' => __('QRs on invoice :no', ['no' => $q]),
                'columns' => [__('QR code'), __('Mode'), __('Gram worth'), __('Cash worth'), __('Status'), __('Sent'), __('Redeemed at')],
                'rows' => $matches->map(fn ($r) => [
                    $r->qr_code, strtoupper((string) $r->qr_mode), number_format((float) $r->gram_worth, 4),
                    $this->money($r->cash_worth), ucfirst((string) $r->status),
                    $r->qr_sent ? __('Yes') : __('No'), $r->redeemed_at?->format('d M Y H:i'),
                ])->all()];

            return;
        }

        $qr = $matches->first();

        $this->sections[] = ['heading' => __('QR'), 'kv' => [
            __('QR code') => $qr->qr_code,
            __('Mode') => strtoupper((string) $qr->qr_mode),
            __('Gram worth') => number_format((float) $qr->gram_worth, 4),
            __('Cash worth') => $this->money($qr->cash_worth),
            __('Status') => ucfirst((string) $qr->status),
            __('Source invoice') => $qr->invoice_no,
            __('Issued at branch') => $qr->branch?->name,
            __('WhatsApp sent') => $qr->qr_sent ? __('Yes') : __('No'),
            __('Sent at') => $qr->sent_at?->format('d M Y H:i'),
            __('OTP sent at') => $qr->otp_sent_at // not cast on the model — raw string
                ? \Illuminate\Support\Carbon::parse($qr->otp_sent_at)->format('d M Y H:i')
                : null,
            __('Redeemed at') => $qr->redeemed_at?->format('d M Y H:i'),
            __('Redeem branch') => $qr->redeemBranch?->name,
        ]];

        if ($qr->member) {
            $this->sections[] = ['heading' => __('Owner'), 'kv' => [
                __('Member code') => $qr->member->member_code,
                __('Name') => $qr->member->name,
                __('Phone') => $qr->member->phone,
                __('Branch') => $qr->member->branch?->name,
            ]];
        }

        if ($inv = RedemptionInvoice::where('redeemable_qr_id', $qr->id)->with('branch')->first()) {
            $this->sections[] = ['heading' => __('Redemption invoice'), 'kv' => [
                __('Invoice #') => $inv->invoice_no,
                __('Date') => $this->dmy($inv->invoice_date),
                __('Taxable total') => $this->money($inv->taxable_total),
                __('CGST') => $this->money($inv->cgst),
                __('SGST') => $this->money($inv->sgst),
                __('Grand total') => $this->money($inv->grand_total),
                __('Payment mode') => strtoupper((string) $inv->payment_mode),
                __('Branch') => $inv->branch?->name,
            ]];
        }
    }
}
