<?php

namespace App\Filament\Pages\Track;

use App\Models\MemberContract;
use App\Models\RdEntry;
use App\Models\RedeemableQr;
use App\Models\RedemptionInvoice;
use Filament\Forms;
use Filament\Forms\Form;

/**
 * Track Contract — contract number (or its sales invoice number) in, full lifecycle
 * out: contract, parent bond, scheme settlement terms, RD trail, QRs, redemptions.
 */
class TrackContract extends TrackPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Track Contract';

    protected static ?string $title = 'Track Contract';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('query')
                    ->label('Contract # / invoice #')
                    ->placeholder('CT-… or INV-…')
                    ->required(),
            ]),
        ])->statePath('data');
    }

    public function lookup(): void
    {
        $q = trim((string) ($this->form->getState()['query'] ?? ''));
        $this->sections = [];
        $this->searched = true;

        $contract = MemberContract::query()
            ->when(static::dealerBranchId(), fn ($qq, $b) => $qq->where('branch_id', $b))
            ->where(fn ($w) => $w->where('contract_no', $q)->orWhere('invoice_no', $q))
            ->with(['member', 'plan', 'branch', 'bond'])
            ->first();

        if (! $contract) {
            return;
        }

        $this->sections[] = ['heading' => __('Contract'), 'kv' => [
            __('Contract #') => $contract->contract_no,
            __('Distributor') => $contract->member ? "{$contract->member->member_code} — {$contract->member->name}" : null,
            __('Scheme') => $contract->plan?->code,
            __('Branch') => $contract->branch?->name,
            __('Invoice #') => $contract->invoice_no,
            __('Amount') => $this->money($contract->amount),
            __('Start') => $this->dmy($contract->start_date),
            __('End') => $this->dmy($contract->end_date),
            __('Status') => ucfirst((string) $contract->status),
            __('Settled on') => $this->dmy($contract->settled_on),
        ]];

        if ($bond = $contract->bond) {
            $this->sections[] = ['heading' => __('Bond'), 'kv' => [
                __('Bond #') => (string) $bond->id,
                __('Bond date') => $this->dmy($bond->bond_date),
                __('Value') => $this->money($bond->value),
                __('Status') => ucfirst((string) $bond->status),
                __('CBC issued') => "{$bond->cbc_issued} / {$bond->cbc_count}",
                __('Level com. issued') => "{$bond->lvlcom_issued} / {$bond->lvlcom_count}",
                __('Return date') => $this->dmy($bond->return_date),
            ]];
        }

        if ($plan = $contract->plan) {
            $this->sections[] = ['heading' => __('Scheme settlement terms'), 'kv' => [
                __('Settlement cycle (months)') => $plan->settlement_cycle_months,
                __('Settlement QR %') => $plan->settlement_qr_pct,
                __('Bonus months') => $plan->settlement_bonus_months,
                __('RD QR grams') => $plan->rd_qr_grams,
                __('RD QR issued') => $plan->rd_qr_on,
                __('Renewal margin') => $plan->renewal_margin,
            ]];
        }

        if ($contract->bond_id) {
            $rd = RdEntry::where('bond_id', $contract->bond_id)->orderBy('paid_on')->limit(60)->get();
            $this->sections[] = ['heading' => __('RD collections'),
                'columns' => [__('Instalment #'), __('Paid on'), __('Amount'), __('Branch')],
                'rows' => $rd->map(fn ($r) => [
                    (string) $r->due_count, $this->dmy($r->paid_on), $this->money($r->value), $r->branch?->name,
                ])->all()];

            $qrs = RedeemableQr::where('bond_id', $contract->bond_id)->latest()->limit(30)->get();
            $this->sections[] = ['heading' => __('Redeemable QRs'),
                'columns' => [__('QR code'), __('Mode'), __('Gram worth'), __('Cash worth'), __('Status'), __('Sent'), __('Redeemed at')],
                'rows' => $qrs->map(fn ($r) => [
                    $r->qr_code, strtoupper((string) $r->qr_mode), number_format((float) $r->gram_worth, 4),
                    $this->money($r->cash_worth), ucfirst((string) $r->status),
                    $r->qr_sent ? __('Yes') : __('No'), $r->redeemed_at?->format('d M Y H:i'),
                ])->all()];

            $inv = RedemptionInvoice::where('bond_id', $contract->bond_id)->latest('invoice_date')->limit(15)->get();
            $this->sections[] = ['heading' => __('Redemption invoices'),
                'columns' => [__('Invoice #'), __('Date'), __('Taxable'), __('CGST'), __('SGST'), __('Grand total'), __('Branch')],
                'rows' => $inv->map(fn ($i) => [
                    $i->invoice_no, $this->dmy($i->invoice_date), $this->money($i->taxable_total),
                    $this->money($i->cgst), $this->money($i->sgst), $this->money($i->grand_total), $i->branch?->name,
                ])->all()];
        }
    }
}
