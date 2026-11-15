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

    /** What a QR is — billing, Gold Saving renewal or settlement (board 2026-11-15). */
    public static function kindLabel(RedeemableQr $qr): string
    {
        $kind = $qr->kind;
        if (! $kind) {
            if ($qr->source === RedeemableQr::SOURCE_LEGACY) {
                return __('Old-system QR');
            }
            // rows from before the column existed: the first QR of a bond is its billing QR
            $kind = $qr->bond_id && RedeemableQr::where('bond_id', $qr->bond_id)->where('id', '<', $qr->id)->exists() ? 'renewal' : 'billing';
        }

        return match ($kind) {
            'settlement' => __('Settlement QR'),
            'renewal' => __('Gold Saving renewal QR'),
            default => __('Billing QR'),
        };
    }

    public function lookup(): void
    {
        $q = trim((string) ($this->form->getState()['query'] ?? ''));
        $this->sections = [];
        $this->searched = true;

        $base = RedeemableQr::query()
            ->when(static::dealerBranchId(), fn ($qq, $b) => $qq
                ->where(fn ($w) => $w->where('branch_id', $b)->orWhere('redeem_branch_id', $b)))
            ->with(['member', 'branch', 'redeemBranch', 'bond.plan']);

        $matches = (clone $base)->where('qr_code', $q)->get();
        if ($matches->isEmpty()) {
            $matches = $base->where('invoice_no', $q)->latest()->limit(25)->get();
        }

        if ($matches->isEmpty()) {
            return;
        }

        if ($matches->count() > 1) {
            $this->sections[] = ['heading' => __('QRs on invoice :no', ['no' => $q]),
                'columns' => [__('QR code'), __('Type'), __('Metal'), __('Gram worth'), __('Cash worth'), __('Status'), __('Generated at'), __('Redeemed at'), __('Redeem branch')],
                'rows' => $matches->map(fn ($r) => [
                    $r->qr_code, static::kindLabel($r), strtoupper((string) $r->qr_mode), number_format((float) $r->gram_worth, 4),
                    $this->money($r->cash_worth), ucfirst((string) $r->status),
                    trim(($r->branch?->name ?? '—') . ' · ' . $this->dmy($r->created_at)),
                    $r->redeemed_at?->format('d M Y H:i'), $r->redeemBranch?->name,
                ])->all()];

            return;
        }

        $qr = $matches->first();

        $inv = RedemptionInvoice::where('redeemable_qr_id', $qr->id)->with(['branch', 'lines', 'restockOrder.approver'])->first();
        $order = $inv?->restockOrder;
        $restocked = $order && in_array($order->status, ['approved', 'delivered', 'billed', 'in_transit'], true);
        $place = fn ($b) => $b ? $b->pickLabel() : null;
        $branchLocked = $qr->bond?->plan ? app(\App\Services\Redeem\RedemptionService::class)->isMetalPurchasePlan($qr->bond->plan) : false;

        // Board 2026-11-15: the QR's life as a road map.
        $this->sections[] = ['heading' => __('Road map'), 'timeline' => [
            ['title' => static::kindLabel($qr) . ' ' . __('generated'), 'when' => $qr->created_at?->format('d M Y H:i'), 'where' => $qr->branch?->name,
                'note' => strtoupper((string) $qr->qr_mode) . ' ' . number_format((float) $qr->gram_worth, 4) . ' g · ₹' . $this->money($qr->cash_worth)
                    . ($qr->invoice_no ? ' · ' . __('from invoice') . ' ' . $qr->invoice_no : '')],
            ['title' => __('Sent to the owner'), 'done' => (bool) $qr->qr_sent, 'when' => $qr->sent_at?->format('d M Y H:i'),
                'note' => $qr->qr_sent ? null : __('Not sent yet')],
            // an old-system redemption carries its invoice but not the QR's own redeemed stamp
            ['title' => __('Redeemed'), 'done' => $qr->status === 'redeemed' || (bool) $inv,
                'when' => $qr->redeemed_at?->format('d M Y H:i') ?? ($inv ? $this->dmy($inv->invoice_date) : null),
                'where' => $qr->redeemBranch?->name ?? $inv?->branch?->name,
                'note' => match (true) {
                    $qr->status === 'cancelled' => __('QR cancelled — it can no longer be redeemed'),
                    (bool) $inv => __('Redemption invoice') . ' ' . $inv->invoice_no . ' · ₹' . $this->money($inv->grand_total),
                    $qr->status === 'redeemed' => null,
                    default => $branchLocked ? __('Redeemable only at') . ' ' . ($qr->branch?->name ?? __('the billing branch')) : __('Redeemable at any dealer'),
                }],
            ['title' => __('Stock given from'), 'done' => (bool) $inv, 'when' => $inv ? $this->dmy($inv->invoice_date) : null,
                'where' => $inv?->branch?->name,
                'note' => $inv ? $inv->lines->map(fn ($l) => rtrim(rtrim(number_format((float) $l->quantity, 3), '0'), '.') . ' × ' . $l->description)->implode(', ') : null],
            ['title' => __('Stock restored (restock)'), 'done' => $restocked || (bool) $inv?->legacy_restock,
                'when' => $restocked ? $order->approved_at?->format('d M Y H:i') : null,
                'where' => $restocked ? __('Head Office') . ' → ' . ($inv->branch?->name ?? '') : null,
                'note' => match (true) {
                    $restocked => __('Restock order') . ' ' . $order->request_no . ' ' . __('approved') . ($order->approver ? ' · ' . $order->approver->name : ''),
                    (bool) $order => __('Restock order') . ' ' . $order->request_no . ' — ' . ucfirst((string) $order->status),
                    (bool) $inv?->legacy_restock => __('Restocked in the old system'),
                    (bool) $inv => __('No restock order raised yet'),
                    default => null,
                }],
        ]];

        $this->sections[] = ['heading' => __('QR'), 'kv' => [
            __('QR code') => $qr->qr_code,
            __('Type') => static::kindLabel($qr),
            __('Metal') => strtoupper((string) $qr->qr_mode),
            __('Scheme') => $qr->bond?->plan ? trim($qr->bond->plan->code . ' — ' . (\App\Support\Translatable::pick($qr->bond->plan->name) ?? '')) : null,
            __('Generated at (branch)') => $place($qr->branch),
            __('Generated on') => $qr->created_at?->format('d M Y H:i'),
            __('Frozen price / g') => $qr->frozen_rate ? '₹' . $this->money($qr->frozen_rate) . ($qr->priced_on ? ' (' . $this->dmy($qr->priced_on) . ')' : '') : null,
            __('Where it can be redeemed') => $branchLocked ? __('Only at the billing branch') : __('Any dealer'),
            __('Redeemed at (branch)') => $place($qr->redeemBranch),
            __('Stock given from') => $inv ? $place($inv->branch) : null,
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

        if ($inv) {
            $this->sections[] = ['heading' => __('Items given at redemption (stock out of :b)', ['b' => $inv->branch?->name ?? '—']),
                'columns' => [__('Item'), __('Metal'), __('Pieces'), __('Weight (g)'), __('Rate'), __('Line total')],
                'rows' => $inv->lines->map(fn ($l) => [
                    $l->description, strtoupper((string) $l->material), rtrim(rtrim(number_format((float) $l->quantity, 3), '0'), '.'),
                    number_format((float) $l->unit_weight * (float) $l->quantity, 4), $this->money($l->rate), $this->money($l->line_total),
                ])->all(),
                'totals' => [__('Total'), '', rtrim(rtrim(number_format((float) $inv->lines->sum('quantity'), 3), '0'), '.'),
                    number_format((float) $inv->lines->sum(fn ($l) => (float) $l->unit_weight * (float) $l->quantity), 4), '', $this->money($inv->lines->sum('line_total'))]];

            if ($order) {
                $this->sections[] = ['heading' => __('Restock order (stock back to the redeeming branch)'), 'kv' => [
                    __('Order #') => $order->request_no,
                    __('Status') => ucfirst((string) $order->status),
                    __('Amount') => $this->money($order->grand_total),
                    __('Raised on') => $this->dmy($order->created_at),
                    __('Approved at') => $order->approved_at?->format('d M Y H:i'),
                    __('Approved by') => $order->approver?->name,
                    __('Stock restored to') => $place($inv->branch),
                ]];
            }

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
