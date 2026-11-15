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

        $this->sections[] = ['heading' => __('Road map'), 'timeline' => $this->timeline($contract)];

        $this->sections[] = ['heading' => __('Contract'), 'kv' => [
            __('Contract #') => $contract->contract_no,
            __('Distributor') => $contract->member ? "{$contract->member->member_code} — {$contract->member->name}" : null,
            __('Scheme') => trim($contract->plan?->code . ' — ' . (\App\Support\Translatable::pick($contract->plan?->name) ?? '')),
            __('Branch') => $contract->branch?->pickLabel(),
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
                __('Gold Saving QR grams') => $plan->rd_qr_grams,
                __('Gold Saving QR issued') => $plan->rd_qr_on,
                __('Renewal margin') => $plan->renewal_margin,
            ]];
        }

        if ($contract->bond_id) {
            $rd = RdEntry::where('bond_id', $contract->bond_id)->orderBy('paid_on')->limit(60)->get();
            $this->sections[] = ['heading' => __('GS collections (Gold Saving)'),
                'columns' => [__('Instalment #'), __('Paid on'), __('Amount'), __('Branch')],
                'rows' => $rd->map(fn ($r) => [
                    (string) $r->due_count, $this->dmy($r->paid_on), $this->money($r->value), $r->branch?->name,
                ])->all()];

            $qrs = RedeemableQr::with(['branch', 'redeemBranch'])->where('bond_id', $contract->bond_id)->latest()->limit(30)->get();
            $this->sections[] = ['heading' => __('Redeemable QRs'),
                'columns' => [__('QR code'), __('Type'), __('Metal'), __('Gram worth'), __('Cash worth'), __('Status'), __('Generated'), __('Redeemed at'), __('Redeem branch')],
                'rows' => $qrs->map(fn ($r) => [
                    $r->qr_code, TrackQr::kindLabel($r), strtoupper((string) $r->qr_mode), number_format((float) $r->gram_worth, 4),
                    $this->money($r->cash_worth), ucfirst((string) $r->status),
                    trim(($r->branch?->name ?? '—') . ' · ' . $this->dmy($r->created_at)),
                    $r->redeemed_at?->format('d M Y H:i'), $r->redeemBranch?->name,
                ])->all()];

            $inv = RedemptionInvoice::with('restockOrder')->where('bond_id', $contract->bond_id)->latest('invoice_date')->limit(15)->get();
            $this->sections[] = ['heading' => __('Redemption invoices'),
                'columns' => [__('Invoice #'), __('Date'), __('Taxable'), __('CGST'), __('SGST'), __('Grand total'), __('Branch')],
                'rows' => $inv->map(fn ($i) => [
                    $i->invoice_no, $this->dmy($i->invoice_date), $this->money($i->taxable_total),
                    $this->money($i->cgst), $this->money($i->sgst), $this->money($i->grand_total), $i->branch?->name,
                ])->all()];

            // Closes the loop (board 2026-08-11): the restock orders each redemption
            // raised — the full chain invoice → contract → QRs → redeem → re-stock
            // now reads on this ONE page.
            $restocks = $inv->map(fn ($i) => ['inv' => $i, 'order' => $i->restockOrder])->filter(fn ($r) => $r['order']);
            $this->sections[] = ['heading' => __('Redemption-sourced restock orders'),
                'columns' => [__('Order #'), __('From redemption'), __('Amount'), __('Status'), __('Requested'), __('Approved at')],
                'rows' => $restocks->map(fn ($r) => [
                    $r['order']->request_no, $r['inv']->invoice_no,
                    $this->money($r['order']->grand_total), ucfirst((string) $r['order']->status),
                    $this->dmy($r['order']->created_at), $r['order']->approved_at?->format('d M Y H:i') ?? '—',
                ])->values()->all()];
        }
    }

    /**
     * The contract's journey, in order, each step with its date and branch / source
     * (board 2026-11-15): billed → contract → collections → QR → redeemed → restocked → settled.
     * A step not reached yet is shown hollow.
     */
    protected function timeline(MemberContract $contract): array
    {
        $bond = $contract->bond;
        $steps = [];
        $at = fn ($branch) => $branch?->name ? $branch->name . ($branch->city ? ' · ' . $branch->city : '') : null;

        $invoiceNo = $contract->invoice_no ?: $bond?->invoice_no;
        $invoice = $invoiceNo ? \App\Models\SalesInvoice::with('branch')->where('invoice_no', $invoiceNo)->first() : null;
        $steps[] = [
            'title' => __('Billed'),
            'when' => $this->dmy($invoice?->date ?? $bond?->bond_date ?? $contract->start_date),
            'where' => $at($invoice?->branch ?? $contract->branch),
            'note' => trim(($invoiceNo ? __('Invoice') . ' ' . $invoiceNo : '')
                . ($invoice ? ' · ₹' . $this->money($invoice->grand_total) . ' · ' . strtoupper((string) $invoice->payment_type) : '')
                . ($contract->source === MemberContract::SOURCE_LEGACY ? ' · ' . __('billed in the old system') : ''), ' ·'),
        ];

        $steps[] = [
            'title' => __('Contract'),
            'when' => $this->dmy($contract->start_date),
            'where' => $at($contract->branch),
            'note' => $contract->contract_no . ' · ' . ($contract->plan?->code ?? '') . ' · ₹' . $this->money($contract->amount)
                . ' · ' . __('valid to') . ' ' . $this->dmy($contract->end_date),
        ];

        if ($bond) {
            $rd = RdEntry::with('branch')->where('bond_id', $bond->id)->orderBy('paid_on')->get();
            if ($rd->isNotEmpty() || $contract->plan?->type === 'rd') {
                $last = $rd->last();
                $steps[] = [
                    'title' => __('Gold Saving collections'),
                    'done' => $rd->isNotEmpty(),
                    'when' => $rd->isNotEmpty() ? $this->dmy($rd->first()->paid_on) . ' → ' . $this->dmy($last->paid_on) : null,
                    'where' => $at($last?->branch),
                    'note' => $rd->isNotEmpty() ? $rd->count() . ' ' . __('collection(s)') . ' · ₹' . $this->money($rd->sum('value')) : __('No renewal collected yet'),
                ];
            }

            $qrs = RedeemableQr::with(['branch', 'redeemBranch'])->where('bond_id', $bond->id)->orderBy('id')->get();
            foreach ($qrs as $qr) {
                $steps[] = [
                    'title' => TrackQr::kindLabel($qr) . ' ' . __('generated'),
                    'when' => $qr->created_at?->format('d M Y H:i'),
                    'where' => $at($qr->branch),
                    'note' => $qr->qr_code . ' · ' . strtoupper((string) $qr->qr_mode) . ' ' . number_format((float) $qr->gram_worth, 4) . ' g · ₹' . $this->money($qr->cash_worth)
                        . ($qr->status === 'cancelled' ? ' · ' . __('CANCELLED') : ''),
                ];
            }

            $redemptions = RedemptionInvoice::with(['branch', 'restockOrder.approver'])->where('bond_id', $bond->id)->orderBy('invoice_date')->orderBy('id')->get();
            if ($redemptions->isEmpty() && $qrs->where('status', 'pending')->isNotEmpty()) {
                $steps[] = ['title' => __('Redeemed'), 'done' => false, 'note' => $qrs->where('status', 'pending')->count() . ' ' . __('QR(s) not redeemed yet')];
            }
            foreach ($redemptions as $inv) {
                $steps[] = [
                    'title' => __('Redeemed'),
                    'when' => $this->dmy($inv->invoice_date),
                    'where' => $at($inv->branch),
                    'note' => __('Redemption invoice') . ' ' . $inv->invoice_no . ' · ₹' . $this->money($inv->grand_total),
                ];
                $order = $inv->restockOrder;
                $restocked = $order && in_array($order->status, ['approved', 'delivered', 'billed', 'in_transit'], true);
                $steps[] = [
                    'title' => __('Restocked'),
                    'done' => $restocked || (bool) $inv->legacy_restock,
                    'when' => $restocked ? $order->approved_at?->format('d M Y H:i') : null,
                    'where' => $restocked ? __('Head Office') . ' → ' . ($inv->branch?->name ?? '') : null,
                    'note' => match (true) {
                        $restocked => __('Restock order') . ' ' . $order->request_no . ' ' . __('approved') . ($order->approver ? ' · ' . $order->approver->name : ''),
                        (bool) $order => __('Restock order') . ' ' . $order->request_no . ' — ' . ucfirst((string) $order->status),
                        (bool) $inv->legacy_restock => __('Restocked in the old system'),
                        default => __('No restock order raised yet'),
                    },
                ];
            }
        }

        $settled = $contract->settled_on !== null || $contract->status === 'closed';
        $rdS = \App\Models\RdSettlement::with('branch')->where('member_contract_id', $contract->id)->first();
        $cs = \App\Models\ContractSettlement::where('member_contract_id', $contract->id)->first();
        $steps[] = [
            'title' => __('Settled'),
            'done' => $settled,
            'when' => $settled ? $this->dmy($contract->settled_on ?? $rdS?->settled_on ?? $cs?->paid_on) : __('due after') . ' ' . $this->dmy($contract->end_date),
            'where' => $at($rdS?->branch) ?? ($cs ? __('Head Office') : null),
            'note' => match (true) {
                (bool) $rdS => ucfirst((string) $rdS->metal) . ' QR ₹' . $this->money($rdS->worth) . ' · ' . $rdS->dues_paid . '/' . $rdS->dues_total . ' ' . __('dues'),
                $cs && $cs->mode === 'gold_qr' => __('Gold QR (redeemable)') . ' ₹' . $this->money($cs->amount),
                (bool) $cs => __('Cash wallet') . ' ₹' . $this->money($cs->amount),
                $settled => __('Closed'),
                default => null,
            },
        ];

        return $steps;
    }
}
