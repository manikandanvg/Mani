<?php

namespace App\Filament\Pages\Reports;

use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\ResellerCommission;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Facades\DB;

/**
 * Earning Report — commissions by stream / member / period with the TDS 5% +
 * service 5% deductions (withheld at commission approval; CBC exempt).
 */
class EarningReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-rupee';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Earning Report';

    protected static ?string $title = 'Earning Report';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(4)->schema([
                Forms\Components\DatePicker::make('from')->required()->native(false),
                Forms\Components\DatePicker::make('to')->required()->native(false),
                Forms\Components\Select::make('type')->label('Stream')
                    ->options(array_combine(
                        $t = ['IC', 'GAP', 'CBC', 'BILL_MARGIN', 'RESELLER', 'PAIRMATCH', 'REFERRAL', 'ROI', 'REWARD'],
                        $t
                    ))
                    ->placeholder(__('All streams'))
                    ->native(false),
                Forms\Components\Select::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid'])
                    ->placeholder(__('All statuses'))
                    ->native(false),
            ]),
        ])->statePath('data');
    }

    public function run(): void
    {
        $d = $this->form->getState();
        [$from, $to] = [$d['from'], $d['to']];
        $branch = static::dealerBranchId();
        $this->sections = [];
        $this->ran = true;

        $base = fn () => CommissionLedger::query()
            ->whereDate('earned_on', '>=', $from)->whereDate('earned_on', '<=', $to)
            ->when($d['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch));

        $streams = $base()
            ->select('type',
                DB::raw('COUNT(*) c'), DB::raw('SUM(amount) gross'),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) pending"),
                DB::raw('SUM(tds) tds'), DB::raw('SUM(service_charge) svc'),
                DB::raw('SUM(COALESCE(net_amount, 0)) net'))
            ->groupBy('type')->get();
        $this->sections[] = ['heading' => __('By stream (commission ledger)'),
            'kv' => [
                __('Gross') => $this->money($streams->sum('gross')),
                __('Pending') => $this->money($streams->sum('pending')),
                __('TDS withheld') => $this->money($streams->sum('tds')),
                __('Service charge') => $this->money($streams->sum('svc')),
                __('Net credited') => $this->money($streams->sum('net')),
            ],
            'columns' => [__('Stream'), __('Entries'), __('Gross'), __('Pending'), __('TDS'), __('Service'), __('Net credited')],
            'rows' => $streams->map(fn ($s) => [
                $s->type, (string) $s->c, $this->money($s->gross), $this->money($s->pending),
                $this->money($s->tds), $this->money($s->svc), $this->money($s->net),
            ])->all()];

        if (empty($d['type']) || $d['type'] === 'CBC') {
            $cbc = CbcEntry::whereDate('cbc_date', '>=', $from)->whereDate('cbc_date', '<=', $to)
                ->select('status', DB::raw('COUNT(*) c'), DB::raw('SUM(worth) worth'))
                ->groupBy('status')->get();
            $this->sections[] = ['heading' => __('CBC entries (deduction-exempt)'),
                'columns' => [__('Status'), __('Entries'), __('Worth')],
                'rows' => $cbc->map(fn ($s) => [ucfirst((string) $s->status), (string) $s->c, $this->money($s->worth)])->all()];
        }

        if (empty($d['type']) || in_array($d['type'], ['BILL_MARGIN', 'RESELLER'], true)) {
            $rc = ResellerCommission::whereDate('bill_date', '>=', $from)->whereDate('bill_date', '<=', $to)
                ->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->select('status', DB::raw('COUNT(*) c'), DB::raw('SUM(com_value) total'))
                ->groupBy('status')->get();
            $this->sections[] = ['heading' => __('Bill margin (reseller commissions)'),
                'columns' => [__('Status'), __('Entries'), __('Total')],
                'rows' => $rc->map(fn ($s) => [ucfirst((string) $s->status), (string) $s->c, $this->money($s->total)])->all()];
        }

        $rows = $base()->with('member')->latest('earned_on')->limit(300)->get();
        $this->sections[] = ['heading' => __('Entries (latest 300)'),
            'columns' => [__('Earned on'), __('Member'), __('Stream'), __('Level'), __('Gross'), __('TDS'), __('Service'), __('Net'), __('Status')],
            'rows' => $rows->map(fn ($r) => [
                $this->dmy($r->earned_on), $r->member?->member_code, $r->type,
                $r->level !== null ? (string) $r->level : '—', $this->money($r->amount),
                $this->money($r->tds), $this->money($r->service_charge), $this->money($r->net_amount),
                ucfirst((string) $r->status),
            ])->all()];
    }
}
