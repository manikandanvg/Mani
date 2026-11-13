<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Models\ResellerCommission;
use App\Models\Stock;
use App\Services\SalesService;
use App\Support\Translatable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Billing engine proof for the board (user 2026-11-13): "each scheme billed at its minimum value,
 * then at double the minimum, senior / referrer LJW01 — invoice, BV distribution, billing margin,
 * scheduled execution (level, CBC), gold margin, QR code — and prove it works like legacy."
 *
 * Every active scheme is billed twice through the REAL engine (SalesService::generateInvoice, the
 * same call the Sales screen makes), to a brand-new distributor placed under the senior. What the
 * engine wrote is read back from the database and checked against
 *
 *   1. the scheme's own settings (bond share, IC %, level %, CBC %, billing margin, GM margin, QR), and
 *   2. a REAL bill of the same scheme from the old system (the imported legacy data): its bond
 *      value, coupon, level amounts, instant commission and billing margin, as legacy recorded them.
 *
 * NOTHING IS KEPT: all of it runs inside one database transaction that is rolled back, with the
 * app flagged as "testing" so no push / WhatsApp message goes out. Safe on the live database.
 *
 *   php artisan billing:proof                         → storage/app/reports/billing-proof-<date>.xlsx
 *   php artisan billing:proof --senior=LJW24395539770 --out="C:\path\file.xlsx"
 *   php artisan billing:proof --keep-cap              leave the senior's earning-potential cap on
 */
class BillingProof extends Command
{
    protected $signature = 'billing:proof {--senior=LJW24395539770 : senior / referrer the test distributors are placed under (user 2026-11-13: ten active levels) — "auto" picks one with ten active levels}
        {--out= : where to write the .xlsx (default storage/app/reports)}
        {--keep-cap : do NOT lift the senior\'s earning-potential cap (commissions will show capped)}';

    protected $description = 'Bill every scheme at its minimum and double-minimum value under a senior, and prove the result against the scheme rules and real legacy bills (rolled back — writes only the report)';

    /** Legacy bills are the ones dated before the new system went live. */
    private const LEGACY_BEFORE = '2026-11-01';

    private array $sheets = [];

    /** @var Member[] level 1 (the senior) … level 10 */
    private array $chain = [];

    private array $depths = [];

    public function handle(SalesService $sales): int
    {
        $senior = strtolower((string) $this->option('senior')) === 'auto'
            ? $this->pickTenLevelSenior()
            : Member::where('member_code', strtoupper((string) $this->option('senior')))->first();
        if (! $senior) {
            $this->error('Senior ' . $this->option('senior') . ' not found (or nobody has ten active levels).');

            return self::FAILURE;
        }
        // the ten earners: the senior is level 1, then nine uplines above
        $this->chain = [$senior];
        while (count($this->chain) < 10 && ($up = end($this->chain)->upline)) {
            $this->chain[] = $up;
        }
        $this->depths = Rank::pluck('depth', 'id')->all();
        $this->info('Senior: ' . $senior->member_code . ' — ' . $senior->name . ' · ' . count($this->chain) . ' levels: ' . implode(' > ', array_map(fn ($m) => $m->member_code, $this->chain)));
        $rate = LiveRate::latestFor('IN');
        if (! $rate) {
            $this->error('No live gold / silver rate is set.');

            return self::FAILURE;
        }
        $plans = Plan::where('is_active', true)->orderBy('code')->get();
        $seniorEp = app(\App\Services\CommissionService::class)->earningPotential($senior);
        $seniorDepth = (int) (Rank::find($senior->rank_id)?->depth ?? 0);

        $env = app()->environment();
        app()->detectEnvironment(fn () => 'testing');   // no push / WhatsApp / login message leaves the box
        DB::beginTransaction();
        try {
            // a dealer branch to bill from (HQ's own bills earn no billing / gold margin), fully stocked
            $branch = Branch::create(['name' => 'BILLING PROOF DEALER', 'country' => 'IN', 'level' => 'reseller', 'kind' => 'dealer', 'is_active' => true, 'invoice_prefix' => 'PROOF']);
            foreach (CatalogProduct::where('is_active', true)->get() as $cp) {
                Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100000000]);
            }
            if (! $this->option('keep-cap')) {
                // an earner cannot earn more in a day than their own bonds are worth: lift that cap for
                // the ten earners so every scheme % is visible even on the crore-value bills
                foreach ($this->chain as $earner) {
                    Bond::create(['member_id' => $earner->id, 'plan_id' => $plans->first()->id, 'branch_id' => $branch->id, 'bond_date' => now()->toDateString(),
                        'value' => 100000000000, 'invoice_no' => 'PROOF-CAP', 'status' => 'active']);
                }
            }

            $n = 0;
            foreach ($plans as $plan) {
                foreach (['MINIMUM' => 1, 'DOUBLE' => 2] as $label => $times) {
                    $n++;
                    // Gold Saving PLUS 1 has no rupee minimum: 1 and 2 pieces of 100 mg at today's price
                    $target = \App\Services\Rd\GoldSavingRules::variant($plan) === \App\Services\Rd\GoldSavingRules::PLUS1
                        ? round(\App\Services\Rd\GoldSavingRules::unitPrice($plan) * $times, 2)
                        : round((float) $plan->min_value * $times, 2);
                    DB::beginTransaction();   // savepoint: every test bill starts from the same clean state
                    try {
                        $this->runCase($sales, $plan, $label, $target, $senior->fresh(), $branch, $rate, $n, $seniorDepth);
                    } catch (\Throwable $e) {
                        $this->row('Summary', array_merge([$n, $plan->code, $this->planName($plan), $label, $target, '—', 'ERROR'], array_fill(0, 20, ''), [mb_substr($e->getMessage(), 0, 300)]));
                        $this->warn("{$plan->code} {$label}: " . $e->getMessage());
                    } finally {
                        DB::rollBack();
                    }
                }
            }
        } finally {
            DB::rollBack();
            app()->detectEnvironment(fn () => $env);
        }

        $path = $this->write($senior, $seniorEp, $seniorDepth, $rate, $plans->count());
        $summary = $this->sheets['Summary'] ?? [];
        $pass = count(array_filter($summary, fn ($r) => ($r[6] ?? '') === 'PASS'));
        $this->info(sprintf('%d test bills · %d PASS · %d not PASS. Nothing was saved to the database.', count($summary), $pass, count($summary) - $pass));
        $this->line('Report: ' . $path);

        return self::SUCCESS;
    }

    private function runCase(SalesService $sales, Plan $plan, string $label, float $target, Member $senior, Branch $branch, LiveRate $rate, int $n, int $seniorDepth): void
    {
        $cart = $this->cartFor($plan, $target, $rate);
        $chainIds = array_map(fn ($m) => $m->id, $this->chain);
        $beforeChain = Member::whereIn('id', $chainIds)->get()->keyBy('id');
        $today = now()->toDateString();

        $invoice = $sales->generateInvoice([
            'branch_id' => $branch->id, 'bill_date' => $today, 'plan_id' => $plan->id, 'payment_type' => 'cash',
            'gold_rate' => (float) $rate->gold, 'silver_rate' => (float) $rate->silver, 'diamond_rate' => 0,
            'referrer_code' => $senior->member_code, 'upline_code' => $senior->member_code,
            'customer' => ['name' => 'PROOF ' . $plan->code . ' ' . $label, 'phone' => '90000' . str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'city' => 'Sivakasi'],
            'cart' => $cart,
        ]);

        $bond = Bond::where('invoice_no', $invoice->invoice_no)->where('member_id', $invoice->customer_member_id)->latest('id')->first();
        $buyer = Member::find($invoice->customer_member_id);
        $afterChain = Member::whereIn('id', $chainIds)->get()->keyBy('id');
        $cross = (float) $invoice->cross_total;
        $grand = (float) $invoice->grand_total;
        $case = [$n, $plan->code, $this->planName($plan), $label];
        $ok = [];

        // ── 1. invoice
        $items = collect($cart)->map(fn ($l) => $l['description'] . ($l['material'] === 'cash' ? '' : ' × ' . (int) $l['qty']))->implode(', ');
        $ok['invoice'] = $grand + 0.005 >= $target && abs($grand - round($cross + (float) $invoice->tax_total, 2)) < 0.01;
        $this->row('Invoice', array_merge($case, [$target, $invoice->invoice_no, Carbon::parse($invoice->date)->format('d-m-Y'), $buyer->member_code, $buyer->name,
            $senior->member_code, $branch->name, $items, $cross, (float) $invoice->cgst, (float) $invoice->sgst, $grand,
            MemberContract::where('bond_id', $bond->id)->value('contract_no') ?: ($plan->is_contract ? 'MISSING' : 'not a contract scheme'), $this->flag($ok['invoice'])]));

        // ── 2. bond (the contract value every later payment hangs off)
        $expBond = round($cross * (float) $plan->allocation / 100, 2);
        $ok['bond'] = abs((float) $bond->value - $expBond) < 0.01 && abs((float) $bond->epin_value - $grand) < 0.01;

        // ── 3. BV distribution
        $expUnpure = round($grand * ($plan->counts_for_rank ? (float) $plan->rank_factor / 100 : 0), 2);
        $bvRows = [
            ['Buyer ' . $buyer->member_code, 'BV', 0.0, (float) $buyer->bv, $grand],
            ['Buyer ' . $buyer->member_code, 'Ranking (unpure) BV', 0.0, (float) $buyer->unpure_bv, $expUnpure],
        ];
        // the bill rolls up to EVERY level above the buyer — the ten levels are listed
        foreach ($this->chain as $i => $m) {
            $was = $beforeChain[$m->id];
            $now = $afterChain[$m->id];
            $who = 'Level ' . ($i + 1) . ' ' . $m->member_code . ($i === 0 ? ' (senior)' : '');
            $bvRows[] = [$who, 'Group BV (GBV)', (float) $was->gbv, (float) $now->gbv, (float) $was->gbv + $grand];
            $bvRows[] = [$who, 'Ranking (unpure) GBV', (float) $was->unpure_gbv, (float) $now->unpure_gbv, (float) $was->unpure_gbv + $expUnpure];
            $bvRows[] = [$who, 'Downline count', (int) $was->downline_count, (int) $now->downline_count, (int) $was->downline_count + 1];
        }
        $ok['bv'] = true;
        foreach ($bvRows as [$who, $what, $was, $now, $exp]) {
            $pass = abs($now - $exp) < 0.01;
            $ok['bv'] = $ok['bv'] && $pass;
            $this->row('BV distribution', array_merge($case, [$grand, $who, $what, $was, $now, round($now - $was, 2), round($exp - $was, 2), $this->flag($pass)]));
        }
        $this->row('BV distribution', array_merge($case, [$grand, 'Bond ' . $bond->invoice_no, 'Bond value = bill × ' . (float) $plan->allocation . ' %', 0.0, (float) $bond->value, (float) $bond->value, $expBond, $this->flag($ok['bond'])]));

        // ── 4. instant commission (IC): 10 levels by the scheme %, paid to the uplines that exist
        $schedule = array_map('floatval', $plan->ic_schedule ?? []);
        $ic = CommissionLedger::where('type', 'IC')->where('bond_id', $bond->id)->get()->keyBy('level');
        $ok['ic'] = true;
        for ($level = 1; $level <= 10; $level++) {
            $pct = $schedule[$level - 1] ?? 0.0;
            $due = round($cross * $pct / 100, 2);
            $booked = (float) ($ic[$level]->amount ?? 0);
            $m = $this->chain[$level - 1] ?? null;
            $earner = $m ? $m->member_code . ' — ' . $m->name . ($m->status === 'active' ? '' : ' (not active)') : '— (no upline at this level)';
            $exp = $m && $m->status === 'active' ? $due : 0.0;
            $paidTo = isset($ic[$level]) ? Member::find($ic[$level]->member_id)?->member_code : '—';
            $pass = ($this->option('keep-cap') ? $booked <= $exp + 0.01 : abs($booked - $exp) < 0.01)
                && (! isset($ic[$level]) || ($m && (int) $ic[$level]->member_id === (int) $m->id));
            $ok['ic'] = $ok['ic'] && $pass;
            $this->row('Instant commission (IC)', array_merge($case, [$cross, $level, $pct, $due, $earner, $booked, $paidTo, $ic[$level]->status ?? '—', $this->flag($pass)]));
        }

        // ── 5. scheduled execution — level income (monthly cut-off on the 1st)
        $levelPct = array_map('floatval', $plan->level_schedule ?? []);
        $lvl = array_map('floatval', (array) ($bond->lvlcom ?? []));
        $months = (int) $bond->lvlcom_count;
        $firstCut = Carbon::parse($bond->bond_date)->addMonthNoOverflow()->startOfMonth();
        $ok['level'] = true;
        foreach ($levelPct as $i => $pct) {
            $exp = round($cross * $pct / 100, 2);
            $got = (float) ($lvl[$i] ?? 0);
            $pass = abs($got - $exp) < 0.01;
            $ok['level'] = $ok['level'] && $pass;
            $this->row('Level schedule', array_merge($case, [$cross, $i + 1, $pct, $exp, $got, $got > 0 ? $months : 0,
                $got > 0 ? $firstCut->format('d-m-Y') : '—', $got > 0 ? $firstCut->copy()->addMonthsNoOverflow($months - 1)->format('d-m-Y') : '—', round($got) * ($got > 0 ? $months : 0),
                $this->levelEarner($i + 1, $got > 0),
                $this->flag($pass)]));
        }

        // ── 6. scheduled execution — CBC coupons
        $cbc = CbcEntry::where('bond_id', $bond->id)->orderBy('cbc_date')->get();
        $expMonthly = (int) $plan->cbc_count > 0 ? round($cross * (float) $plan->cbc_value / 100, 2) : 0.0;
        $expCount = $expMonthly > 0 ? (int) $plan->cbc_count : 0;
        $days = min(30, Carbon::parse($bond->bond_date)->diffInDays($firstCut));
        $expFirst = $expMonthly > 0 ? round($expMonthly / 30 * $days, 2) : 0.0;
        $ok['cbc'] = $cbc->count() === $expCount
            && ($expCount === 0 || (abs((float) $cbc->first()->worth - $expFirst) < 0.01 && ($expCount < 2 || abs((float) $cbc[1]->worth - $expMonthly) < 0.01)));
        $this->row('CBC schedule', array_merge($case, [$cross, (float) $plan->cbc_value, (int) $plan->cbc_count, $expMonthly, $expCount, $cbc->count(),
            $cbc->first() ? Carbon::parse($cbc->first()->cbc_date)->format('d-m-Y') : '—', $expFirst, (float) ($cbc->first()->worth ?? 0),
            (float) ($cbc[1]->worth ?? 0), $cbc->last() ? Carbon::parse($cbc->last()->cbc_date)->format('d-m-Y') : '—', (float) $cbc->sum('worth'), $buyer->member_code, $this->flag($ok['cbc'])]));

        // ── 7. billing margin + gold / silver margin (to the billing branch)
        $rc = ResellerCommission::where('invoice_no', $invoice->invoice_no)->where('branch_id', $branch->id)->get()->groupBy('com_type_id');
        $expBill = round($cross * (float) $plan->billing_margin / 100, 2);
        $gotBill = (float) ($rc[ResellerCommission::COM_BILL_MARGIN] ?? collect())->sum('com_value');
        $ok['bill_margin'] = abs($gotBill - $expBill) < 0.01;
        $this->row('Margins', array_merge($case, [$cross, 'Billing margin', (float) $plan->billing_margin . ' % of the bill', $expBill, $gotBill, $branch->name, $this->flag($ok['bill_margin'])]));
        $ok['gm'] = true;
        foreach (['gold' => ResellerCommission::COM_GOLD_MARGIN, 'silver' => ResellerCommission::COM_SILVER_MARGIN] as $metal => $type) {
            $exp = 0.0;
            $basis = [];
            foreach ($cart as $l) {
                if ($l['material'] === $metal) {
                    $gm = (float) CatalogProduct::find($l['catalog_product_id'])->gm_margin;
                    $exp += (float) $l['weight'] * $gm;
                    $basis[] = (float) $l['weight'] . ' g × ₹' . $gm . '/g';
                }
            }
            $got = (float) ($rc[$type] ?? collect())->sum('com_value');
            $pass = abs($got - round($exp, 2)) < 0.01;
            $ok['gm'] = $ok['gm'] && $pass;
            $this->row('Margins', array_merge($case, [$cross, ucfirst($metal) . ' margin', $basis ? implode(' + ', $basis) : 'no ' . $metal . ' on this bill', round($exp, 2), $got, $branch->name, $this->flag($pass)]));
        }

        // ── 8. QR code
        $qrs = RedeemableQr::where('bond_id', $bond->id)->get();
        $expQr = $plan->is_redeem && $plan->rd_qr_on !== 'first_renewal';
        $expWorth = ! $expQr ? 0.0 : match (true) {
            // Gold Saving PLUS 1 (board 2026-11-13): the QR backs every 100 mg piece billed = the bill
            (float) $plan->rd_qr_grams > 0 => round((float) app(\App\Services\Qr\GoldQrPricing::class)->price($plan) * max(1, (int) $bond->rd_pieces), 2),
            (float) $plan->allocation_cont > 0 => round($grand * (float) $plan->allocation_cont / 100, 2),
            default => $grand,
        };
        $qr = $qrs->first();
        $ok['qr'] = $expQr ? ($qrs->count() === 1 && abs((float) $qr->cash_worth - $expWorth) < 0.01) : $qrs->isEmpty();
        $why = ! $plan->is_redeem ? 'scheme is not redeemable — no QR' : ($plan->rd_qr_on === 'first_renewal' ? 'QR is issued at the first renewal, not at billing'
            : ((float) $plan->rd_qr_grams > 0 ? max(1, (int) $bond->rd_pieces) . ' × ' . (float) $plan->rd_qr_grams . ' g gold at today\'s price incl. charges + GST'
                : ((float) $plan->allocation_cont > 0 ? 'bill × ' . (float) $plan->allocation_cont . ' % (contract share)' : 'the full bill')));
        $this->row('QR code', array_merge($case, [$grand, $expQr ? 'Yes' : 'No', $why, $expWorth, $qrs->count(), $qr->qr_code ?? '—', $qr->qr_mode ?? '—',
            (float) ($qr->cash_worth ?? 0), $qr && $qr->gram_worth !== null ? (float) $qr->gram_worth : '—', $qr->status ?? '—', $this->flag($ok['qr'])]));

        // ── 9. the same scheme on a REAL legacy bill
        $legacy = $this->legacyCompare($plan, $cross, $case);

        $all = ! in_array(false, $ok, true) && $legacy !== 'DIFFERENT';
        // the Summary carries the VALUES next to each check (user 2026-11-13: "provide value")
        $goldMargin = (float) ($rc[ResellerCommission::COM_GOLD_MARGIN] ?? collect())->sum('com_value');
        $silverMargin = (float) ($rc[ResellerCommission::COM_SILVER_MARGIN] ?? collect())->sum('com_value');
        // every column is a VALUE (user 2026-11-13: "show value on excel"); the checks are summed up in one cell
        $failed = array_keys(array_filter($ok, fn ($v) => $v === false));
        $levelMonths = array_sum($lvl) > 0 ? $months : 0;
        $this->row('Summary', array_merge($case, [$target, $invoice->invoice_no, $all ? 'PASS' : 'CHECK', $grand, $cross,
            (float) $bond->value, (float) $buyer->bv, (float) $buyer->unpure_bv,
            (float) $ic->sum('amount'), $ic->count(),
            round(array_sum($lvl), 2), $levelMonths, round(array_sum(array_map('round', $lvl)) * $levelMonths, 2),
            (float) ($cbc[1]->worth ?? ($cbc->first()->worth ?? 0)), $cbc->count(), (float) $cbc->sum('worth'),
            $gotBill, $goldMargin, $silverMargin,
            (float) ($qr->cash_worth ?? 0), $qr && $qr->gram_worth !== null ? (float) $qr->gram_worth : 0.0, $qr->qr_code ?? 'no QR at billing',
            $failed ? 'FAILED: ' . implode(', ', $failed) : 'all ' . count($ok) . ' checks pass', $legacy]));
        $this->line(sprintf('%-5s %-8s ₹%s  %s  legacy: %s', $plan->code, $label, number_format($grand, 2), $all ? 'PASS' : 'CHECK', $legacy));
    }

    /**
     * Cart exactly as the Sales screen sends it. Cash schemes: one CASH line of the bill value.
     * Gold / silver purchase schemes: whole pieces of the smallest coin until the bill reaches the value.
     */
    private function cartFor(Plan $plan, float $target, LiveRate $rate): array
    {
        $material = in_array($plan->type, ['gold', 'silver'], true) ? $plan->type : 'cash';
        $cp = CatalogProduct::where('is_active', true)->where('material', $material)->where('is_custom_order', false)
            ->orderBy('default_weight')->firstOrFail();

        if ($material === 'cash') {
            return [['catalog_product_id' => $cp->id, 'material' => 'cash', 'description' => 'CASH', 'weight' => $target, 'rate' => 1, 'qty' => null,
                'net_total' => $target, 'grand_total' => $target, 'making' => 0, 'wastage' => 0]];
        }
        $perGram = (float) ($material === 'silver' ? $rate->silver : $rate->gold);
        $unit = (float) $cp->default_weight;
        $net1 = $unit * $perGram * (1 + ((float) $cp->making_charge_pct + (float) $cp->wastage_charge_pct) / 100) + (float) $cp->hallmark_charge;
        $grand1 = $net1 * (1 + (float) $cp->gst_pct / 100);
        $qty = (int) max(1, ceil($target / $grand1 - 1e-9));
        $weight = round($qty * $unit, 4);
        $base = $weight * $perGram;
        $net = round($base * (1 + ((float) $cp->making_charge_pct + (float) $cp->wastage_charge_pct) / 100) + (float) $cp->hallmark_charge, 2);

        return [['catalog_product_id' => $cp->id, 'material' => $material, 'description' => (string) Translatable::pick($cp->name), 'weight' => $weight, 'rate' => $perGram,
            'qty' => $qty, 'unit_weight' => $unit, 'net_total' => $net, 'grand_total' => round($net + round($net * (float) $cp->gst_pct / 100, 2), 2),
            'making' => $base * (float) $cp->making_charge_pct / 100, 'wastage' => $base * (float) $cp->wastage_charge_pct / 100]];
    }

    /**
     * A real legacy bill of this scheme — same bill value when one exists, else the latest one —
     * compared as "share of the bill": bond, coupon, level amounts, instant commission, billing margin.
     */
    private function legacyCompare(Plan $plan, float $cross, array $case): string
    {
        $base = DB::table('bonds as b')->join('sales_invoices as s', 's.invoice_no', '=', 'b.invoice_no')
            ->where('b.plan_id', $plan->id)->where('b.bond_date', '<', self::LEGACY_BEFORE)->where('s.net_total', '>', 0)
            ->select('b.id', 'b.invoice_no', 'b.bond_date', 'b.value', 'b.cbc_value', 'b.cbc_count', 'b.lvlcom', 's.net_total', 's.branch_id');
        $same = (clone $base)->whereBetween('s.net_total', [$cross - 0.5, $cross + 0.5])->orderByDesc('b.id')->first();
        $ref = $same ?: (clone $base)->orderByDesc('b.id')->first();
        if (! $ref) {
            $this->row('Legacy comparison', array_merge($case, [$cross, '—', '—', 0, 'This scheme has no bill in the old system', '', '', '', 'NO LEGACY BILL']));

            return 'no legacy bill';
        }
        $bill = (float) $ref->net_total;
        $head = array_merge($case, [$cross, $ref->invoice_no . ($same ? ' (same value)' : ' (latest bill of the scheme)'), Carbon::parse($ref->bond_date)->format('d-m-Y'), $bill]);
        $pctOf = fn (float $v) => $bill > 0 ? round($v / $bill * 100, 4) : 0.0;
        $result = 'MATCH';
        $add = function (string $what, $legacyAmount, float $legacyPct, float $enginePct, $engineAmount, ?bool $pass = null) use (&$result, $head) {
            $pass ??= abs($legacyPct - $enginePct) < 0.011;
            if (! $pass) {
                $result = 'DIFFERENT';
            }
            $this->row('Legacy comparison', array_merge($head, [$what, $legacyAmount, $legacyPct, $enginePct, $engineAmount, $pass ? 'MATCH' : 'DIFFERENT']));
        };

        $add('Bond value (share of the bill %)', (float) $ref->value, $pctOf((float) $ref->value), (float) $plan->allocation, round($cross * (float) $plan->allocation / 100, 2));
        $cbcPct = (int) $plan->cbc_count > 0 ? (float) $plan->cbc_value : 0.0;
        $add('CBC coupon per month %', (float) $ref->cbc_value, $pctOf((float) $ref->cbc_value), $cbcPct, round($cross * $cbcPct / 100, 2));
        $add('CBC number of months', (int) $ref->cbc_count, (float) $ref->cbc_count, (float) ($cbcPct > 0 ? $plan->cbc_count : 0), (int) ($cbcPct > 0 ? $plan->cbc_count : 0),
            (int) $ref->cbc_count === (int) ($cbcPct > 0 ? $plan->cbc_count : 0));
        $legacyLvl = array_map('floatval', (array) json_decode((string) $ref->lvlcom, true));
        foreach (array_map('floatval', $plan->level_schedule ?? []) as $i => $pct) {
            $add('Level ' . ($i + 1) . ' income per month %', $legacyLvl[$i] ?? 0.0, $pctOf($legacyLvl[$i] ?? 0.0), $pct, round($cross * $pct / 100, 2));
        }

        // instant commission: legacy paid each level that had an upline; every row it paid must be a scheme %
        $paid = DB::table('commission_ledger')->where('type', 'IC')->where('invoice_no', $ref->invoice_no)
            ->whereDate('earned_on', substr((string) $ref->bond_date, 0, 10))->pluck('amount')->map(fn ($a) => round((float) $a, 2))->all();
        foreach (array_map('floatval', $plan->ic_schedule ?? []) as $i => $pct) {
            $want = round($bill * $pct / 100, 2);
            $at = $want > 0 ? array_search($want, $paid, false) : false;
            if ($at !== false) {
                unset($paid[$at]);
            }
            if ($pct <= 0) {
                continue;
            }
            // level 1 always has an upline; deeper levels only when the legacy chain was that long
            $this->row('Legacy comparison', array_merge($head, ['Instant commission level ' . ($i + 1) . ' %', $at !== false ? $want : 'not paid (chain shorter / capped)',
                $at !== false ? $pct : '', $pct, round($cross * $pct / 100, 2), $at !== false ? 'MATCH' : ($i === 0 ? 'NOT FOUND' : 'n/a')]));
        }

        $bm = (float) $plan->billing_margin;
        if ($bm > 0) {
            $want = round($bill * $bm / 100, 2);
            $found = (int) $ref->branch_id === SalesService::HQ_BRANCH_ID ? null
                : DB::table('reseller_commissions')->where('invoice_no', $ref->invoice_no)->where('com_type_id', ResellerCommission::COM_BILL_MARGIN)
                    ->whereBetween('com_value', [$want - 0.01, $want + 0.01])->exists();
            $this->row('Legacy comparison', array_merge($head, ['Billing margin %', $found === null ? 'billed at HQ — no margin' : ($found ? $want : 'not found'),
                $found ? $bm : '', $bm, round($cross * $bm / 100, 2), $found === null ? 'n/a' : ($found ? 'MATCH' : 'NOT FOUND')]));
        }

        return $result;
    }

    /** Who receives the monthly level income of a level, and whether their rank reaches it today. */
    private function levelEarner(int $level, bool $due): string
    {
        $m = $this->chain[$level - 1] ?? null;
        if (! $m) {
            return '— (no upline at this level)';
        }
        $depth = (int) ($this->depths[$m->rank_id] ?? 0);

        return $m->member_code . ' — ' . $m->name . ($due ? ($depth >= $level ? ' (rank ' . $depth . ': qualifies)' : ' (rank ' . $depth . ': paid once rank reaches ' . $level . ')') : '');
    }

    /**
     * A senior with TEN active levels: the senior and nine uplines above, every one active and
     * holding an active bond, so a bill under the senior pays all ten instant-commission levels.
     * Among those, the one whose first five levels best meet the rank needed for level income.
     */
    private function pickTenLevelSenior(): ?Member
    {
        $members = DB::table('members')->whereNull('deleted_at')->get(['id', 'upline_id', 'status', 'rank_id'])->keyBy('id');
        $bonded = DB::table('bonds')->where('status', 'active')->selectRaw('member_id, SUM(value) as v')->groupBy('member_id')->pluck('v', 'member_id');
        $depths = Rank::pluck('depth', 'id');
        $best = null;
        $bestScore = [-1, -1.0];
        foreach ($members as $m) {
            $chain = [];
            $at = $m;
            $seen = [];
            while ($at && count($chain) < 10 && ! isset($seen[$at->id])) {
                if ($at->status !== 'active' || ! isset($bonded[$at->id])) {
                    break;
                }
                $seen[$at->id] = true;
                $chain[] = $at;
                $at = $at->upline_id ? ($members[$at->upline_id] ?? null) : null;
            }
            if (count($chain) < 10) {
                continue;
            }
            $qualify = 0;
            foreach (array_slice($chain, 0, 5) as $i => $c) {
                $qualify += (int) (($depths[$c->rank_id] ?? 0) >= $i + 1);
            }
            $score = [$qualify, (float) min(array_map(fn ($c) => (float) $bonded[$c->id], $chain))];
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $m->id;
            }
        }

        return $best ? Member::find($best) : null;
    }

    private function planName(Plan $plan): string
    {
        return (string) (Translatable::pick($plan->name) ?: $plan->code);
    }

    private function flag(bool $ok): string
    {
        return $ok ? 'PASS' : 'FAIL';
    }

    private function row(string $sheet, array $values): void
    {
        $this->sheets[$sheet][] = $values;
    }

    private function write(Member $senior, float $seniorEp, int $seniorDepth, LiveRate $rate, int $plans): string
    {
        $path = $this->option('out') ?: storage_path('app/reports/billing-proof-' . now()->format('Y-m-d-His') . '.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $case = ['#', 'Scheme', 'Scheme name', 'Test'];
        $heads = [
            'Summary' => array_merge($case, ['Bill value asked ₹', 'Invoice no', 'RESULT', 'Bill total ₹', 'Taxable ₹',
                'Bond value ₹', 'BV added (buyer BV / each upline GBV) ₹', 'Ranking BV added ₹',
                'Instant commission ₹ (all levels)', 'IC levels paid',
                'Level income per month ₹ (L1–5)', 'Level months', 'Level income over the term ₹',
                'CBC per month ₹', 'CBC coupons', 'CBC total ₹',
                'Billing margin ₹', 'Gold margin ₹', 'Silver margin ₹',
                'QR worth ₹', 'QR gold grams', 'QR code', 'Checks', 'Same as legacy bill']),
            'Invoice' => array_merge($case, ['Bill value asked ₹', 'Invoice no', 'Bill date', 'Distributor ID', 'Distributor', 'Senior / referrer', 'Billed at', 'Items', 'Taxable ₹', 'CGST ₹', 'SGST ₹', 'Grand total ₹', 'Contract no', 'Check']),
            'BV distribution' => array_merge($case, ['Bill total ₹', 'Who', 'What', 'Before', 'After', 'Change', 'Change expected', 'Check']),
            'Instant commission (IC)' => array_merge($case, ['Bill (taxable) ₹', 'Level', 'Scheme %', 'Amount by scheme ₹', 'Earner at this level', 'Booked by engine ₹', 'Booked to', 'Status', 'Check']),
            'Level schedule' => array_merge($case, ['Bill (taxable) ₹', 'Level', 'Scheme % per month', 'Expected per month ₹', 'On the bond ₹', 'Months', 'First cut-off', 'Last cut-off', 'Total over the term ₹', 'Earner', 'Check']),
            'CBC schedule' => array_merge($case, ['Bill (taxable) ₹', 'Scheme CBC %', 'Scheme months', 'Expected per month ₹', 'Coupons expected', 'Coupons scheduled', 'First coupon date', 'First coupon expected ₹ (part month)', 'First coupon ₹', 'Monthly coupon ₹', 'Last coupon date', 'Total ₹', 'Coupon holder', 'Check']),
            'Margins' => array_merge($case, ['Bill (taxable) ₹', 'Margin', 'Rule', 'Expected ₹', 'Paid by engine ₹', 'Paid to branch', 'Check']),
            'QR code' => array_merge($case, ['Bill total ₹', 'QR expected', 'Rule', 'Worth expected ₹', 'QRs issued', 'QR code', 'Metal', 'Worth ₹', 'Gold grams', 'Status', 'Check']),
            'Legacy comparison' => array_merge($case, ['Test bill (taxable) ₹', 'Legacy invoice', 'Legacy bill date', 'Legacy bill ₹', 'What is compared', 'Legacy recorded ₹', 'Legacy % of its bill', 'New engine %', 'New engine ₹ on the test bill', 'Result']),
        ];
        $notes = [
            ['Billing engine proof — ' . now()->format('d-m-Y H:i') . ' (' . config('app.url') . ')'],
            ['What was done', 'Every active scheme (' . $plans . ') was billed twice — at its minimum value and at double the minimum — through the same billing engine the Sales screen uses.'],
            ['Senior / referrer', $senior->member_code . ' — ' . $senior->name . '. Each test bill went to a brand-new distributor placed directly under this senior.'],
            ['The ten levels', implode('  >  ', array_map(fn ($m, $i) => 'L' . ($i + 1) . ' ' . $m->member_code . ' ' . $m->name, $this->chain, array_keys($this->chain)))],
            ['Billed at', 'A temporary dealer branch (head office bills earn no billing / gold margin), stocked for the test.'],
            ['Rates used', 'Gold ₹' . number_format((float) $rate->gold, 2) . '/g · Silver ₹' . number_format((float) $rate->silver, 2) . '/g (live rate at the time of the run).'],
            ['Nothing was saved', 'All test bills ran inside one database transaction that was rolled back, and no push / WhatsApp message was sent. Invoice numbers, IDs and QR codes shown here do not exist in the system.'],
            ['Checks', 'PASS = what the engine wrote equals the scheme rule to the paisa. Each sheet shows the expected figure next to the engine figure.'],
            ['Legacy comparison', 'A real bill of the same scheme from the old system (bills before 01-11-2026): its bond value, coupon, level income, instant commission and billing margin, each as a % of that bill, against the % the new engine uses.'],
            ['Instant commission levels', 'The senior is level 1 and has ' . (count($this->chain) - 1) . ' active uplines above, so a bill pays ' . count($this->chain) . ' levels. Each row shows the earner at that level, the scheme amount and who the engine booked it to.'],
            ['Earning-potential cap', $this->option('keep-cap')
                ? 'Left ON: an earner can earn at most the value of their own active bonds in a day (same rule as legacy), so the larger bills show capped commissions.'
                : 'An earner can earn at most the value of their own active bonds in a day (same rule as legacy). For this test the cap was lifted for the ten earners so every scheme percentage can be seen, even on the crore-value bills. Run with --keep-cap to see the capped figures. (Senior\x27s own bonds today: ₹' . number_format($seniorEp, 2) . '.)'],
            ['Level income', 'Paid by the monthly cut-off on the 1st, for the months shown, when the earner\'s rank reaches that level. Senior rank depth today: ' . $seniorDepth . '.'],
        ];

        $bold = (new Style)->setFontBold();
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;
        foreach ($heads as $name => $head) {
            $first ? $writer->getCurrentSheet()->setName($name) : $writer->addNewSheetAndMakeItCurrent()->setName($name);
            $first = false;
            $writer->addRow(Row::fromValues($head, $bold));
            foreach ($this->sheets[$name] ?? [] as $r) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => is_float($v) ? round($v, 4) : $v, $r)));
            }
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('How this was tested');
        foreach ($notes as $i => $r) {
            $writer->addRow(Row::fromValues($r, $i === 0 ? $bold : null));
        }
        $writer->close();

        return $path;
    }
}
