<?php

namespace App\Support\Gst;

use App\Models\Branch;
use App\Models\Plan;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Auditor-format GST registers — parity with the legacy admin/generate/retailerreport
 * (AGSTRSBTC / AGSTRSBTB) that the auditor has been filing from (Nov 2026):
 *
 *  - B2C  = consumers' gold redeemed under the filing GSTIN (LORD JEWELLER
 *    33AREPB3962B3ZD): one row per sales-cart line of a bonded sales invoice,
 *    Gold Saving / forward-contract plans excluded (no gold changes hands at billing),
 *    "BIS 916 HUID GOLD", HSN 71181000, GST 3 % split CGST/SGST.
 *  - B2B  = stock supplied to dealers (sub dealer / retailer / area / wholesaler …):
 *    every approved stock request + redemption restock, consolidated to one row per
 *    order, buyer = the dealer branch with its own GSTIN. Orders whose buyer carries the
 *    filing GSTIN itself (legacy merged "LORD JEWELLER" records) are not a B2B supply and
 *    are left out — they are counted in notes() so HQ can fix the branch record.
 *    B2B amounts are tax-reversed from the billed order total (BILLING VALUE = taxable + GST
 *    exactly, as the auditor files them); redemption restocks carry the customer's original
 *    redeemed value, not the restock-day cart price.
 *
 * Both registers get a running INV NO that continues the auditor's own sequence
 * (B2C …2216 → 2217, B2B …5270 → 5271 after the September 2026 filing) and carry the
 * original sales invoice / order number as REF NO. The sequence is remembered per
 * register in settings (group gst_auditor) so the next month continues automatically
 * and re-exporting a month reuses its own start number.
 *
 * Source: "legacy" reads the Lord DB (lordicl) with the legacy report's exact SQL —
 * the only place approved pre-go-live orders exist; "new" reads the new system's
 * sales_invoices / sale_lines / bonds and branch_order_requests / lines.
 */
class GstAuditorReport
{
    public const SOURCE_LEGACY = 'legacy';

    public const SOURCE_NEW = 'new';

    public const SETTINGS_GROUP = 'gst_auditor';

    public const HSN = '71181000';

    public const GST_PCT = 3.0;

    /** Making + wastage share assumed when an order has no priced cart lines (2.5 % + 2.5 %). */
    public const B2B_DEFAULT_CHARGE_PCT = 5.0;

    public const B2C_ITEM = 'BIS 916 HUID GOLD';

    public const B2B_ITEM = 'CONSOLIDATED CART ITEMS';

    /** Where the auditor's numbering stood after the September 2026 filing. */
    public const FIRST_NUMBER = ['b2c' => 2217, 'b2b' => 5271];

    /** Legacy planids excluded from B2C (Gold Saving PLUS2 / PLUS3, forward contracts). */
    public const LEGACY_EXCLUDED_PLANS = [200, 209, 202];

    /** The same plans in the new scheme sheet (codes were reshuffled on import). */
    public const EXCLUDED_PLAN_CODES = ['P200', 'P208', 'P202'];

    public const B2C_COLUMNS = ['INV NO', 'INV DATE', 'REF NO', 'CUSID', 'CUSNAME', 'ADDRESS', 'CITY', 'PHONE', 'PAN', 'AADHAR',
        'BILL MODE', 'GST NUMBER', 'ITEM DESCRIPTION', 'HSN CODE', 'QTY', 'BILLING VALUE', 'GOLD WEIGHT(GM)', 'GOLD RATE/G',
        'NET VALUE', 'CHARGES  (MC(2.5%)+WC(2.5%))', 'TAXABLE AMOUNT', 'GST (3%)', 'SGST', 'CGST'];

    public const B2B_COLUMNS = ['INV NO', 'INV DATE', 'REF NO', 'CUSID', 'CUSNAME', 'ADDRESS', 'CITY', 'CONTACT', 'BILL MODE',
        'GST NUMBER', 'ITEM DESCRIPTION', 'HSN CODE', 'QTY', 'BILLING VALUE', 'GOLD WEIGHT(GM)', 'ITEM RATE/G', 'NET VALUE',
        'CHARGES  (MC(2.5%)+WC(2.5%))', 'TAXABLE AMOUNT', 'GST (3%)', 'SGST', 'CGST'];

    /** @var array<int, string> */
    public array $notes = [];

    public readonly string $gstin;

    public function __construct(
        public readonly string $source,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $branchId = null,
        ?string $gstin = null,
    ) {
        $this->gstin = strtoupper(trim($gstin ?: (string) self::filingGstin()));
    }

    /** The GSTIN the return is filed under — the HQ branch's. */
    public static function filingGstin(): ?string
    {
        return Branch::query()->orderBy('id')->whereNotNull('gst_no')->where('gst_no', '<>', '')->value('gst_no');
    }

    public static function legacyAvailable(): bool
    {
        try {
            DB::connection('legacy')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function sourceOptions(): array
    {
        return [self::SOURCE_NEW => __('New system'), self::SOURCE_LEGACY => __('Lord DB (legacy, pre go-live months)')];
    }

    // ------------------------------------------------------------------ numbering

    /** Start number for a register: this month's own start if it was exported before, else the next free number. */
    public static function nextNumber(string $register, string $from, string $to): int
    {
        $runs = self::runs($register);
        foreach ($runs as $r) {
            if (($r['from'] ?? null) === $from && ($r['to'] ?? null) === $to) {
                return (int) $r['start'];
            }
        }
        $last = 0;
        foreach ($runs as $r) {
            $last = max($last, (int) ($r['end'] ?? 0));
        }

        return $last > 0 ? $last + 1 : self::FIRST_NUMBER[$register];
    }

    /** Remember the range an export used so the sequence continues next month. */
    public static function recordRun(string $register, string $from, string $to, int $start, int $count): void
    {
        $runs = array_values(array_filter(self::runs($register), fn ($r) => ! (($r['from'] ?? null) === $from && ($r['to'] ?? null) === $to)));
        $runs[] = ['from' => $from, 'to' => $to, 'start' => $start, 'end' => $count > 0 ? $start + $count - 1 : $start - 1,
            'rows' => $count, 'exported_at' => now()->toDateTimeString()];
        DB::table('settings')->updateOrInsert(['group' => self::SETTINGS_GROUP, 'key' => $register.'_runs'],
            ['value' => json_encode($runs), 'type' => 'json', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<int, array{from:string,to:string,start:int,end:int,rows:int,exported_at:string}> */
    public static function runs(string $register): array
    {
        $v = DB::table('settings')->where('group', self::SETTINGS_GROUP)->where('key', $register.'_runs')->value('value');
        $runs = $v ? json_decode((string) $v, true) : [];

        return is_array($runs) ? $runs : [];
    }

    /** Stamp the running INV NO on rows (first column). */
    public static function number(array $rows, int $start): array
    {
        $n = $start;
        foreach ($rows as &$row) {
            $row['INV NO'] = $n++;
        }

        return $rows;
    }

    // ------------------------------------------------------------------ registers

    /** @return array<int, array<string, mixed>> keyed by the B2C column names (INV NO left null) */
    public function b2cRows(): array
    {
        return $this->source === self::SOURCE_LEGACY ? $this->legacyB2c() : $this->newB2c();
    }

    /** @return array<int, array<string, mixed>> keyed by the B2B column names (INV NO left null) */
    public function b2bRows(): array
    {
        return $this->source === self::SOURCE_LEGACY ? $this->legacyB2b() : $this->newB2b();
    }

    /** Totals of a register (billing, taxable, gst). */
    public static function totals(array $rows): array
    {
        return [
            'rows' => count($rows),
            'billing' => round(array_sum(array_column($rows, 'BILLING VALUE')), 2),
            'taxable' => round(array_sum(array_column($rows, 'TAXABLE AMOUNT')), 2),
            'gst' => round(array_sum(array_column($rows, 'GST (3%)')), 2),
        ];
    }

    // ------------------------------------------------------------------ legacy source (Lord DB)

    protected function legacy(): ConnectionInterface
    {
        return DB::connection('legacy');
    }

    protected function legacyBrid(): ?int
    {
        if (! $this->branchId) {
            return null;
        }
        $brid = Branch::whereKey($this->branchId)->value('legacy_brid');
        if (! $brid) {
            $this->notes[] = __('Branch #:id has no legacy branch id — the Lord DB has nothing for it.', ['id' => $this->branchId]);
        }

        return $brid ? (int) $brid : 0;
    }

    protected function legacyB2c(): array
    {
        $brid = $this->legacyBrid();
        $filter = $brid === null
            ? 'c.branchid IN (SELECT brid FROM tbl_branches WHERE gstno = ?)'
            : 'c.branchid = ?';
        $rows = $this->legacy()->select(
            'SELECT s.estid, s.rollno, s.estdate, s.cusid, s.cusname, s.cusaddress, s.cucity, s.cucontact, s.cupan, s.cuaadhar,
                    b.planid, b.epinvalue, c.weight AS cg_gw, c.purprice AS matprice, c.mcharge AS cg_mc, c.wcharge AS cg_wc,
                    c.aftercharges AS cg_aftercharge
             FROM tbl_salesinvoice s
             LEFT JOIN tbl_bond b ON b.invoiceno = s.estid
             LEFT JOIN tbl_sales_cart c ON c.invno = s.estid
             WHERE s.estdate BETWEEN ? AND ? AND '.$filter.'
             ORDER BY s.estdate ASC, s.estid ASC',
            [$this->from, $this->to, $brid === null ? $this->gstin : $brid]);

        $out = [];
        foreach ($rows as $r) {
            if (in_array((int) $r->planid, self::LEGACY_EXCLUDED_PLANS, true)) {
                continue;
            }
            $taxable = (float) $r->cg_aftercharge;
            if (! ((float) $r->epinvalue > 0 && $taxable * (1 + self::GST_PCT / 100) > 0) || trim((string) $r->cusid) === 'LORD JEWELLER') {
                continue;
            }
            $out[] = $this->b2cRow($r->estdate, (string) $r->estid, (string) $r->cusid, $r->cusname, $r->cusaddress, $r->cucity,
                $r->cucontact, $r->cupan, $r->cuaadhar, (float) $r->cg_gw, (float) $r->matprice, (float) $r->cg_mc + (float) $r->cg_wc, $taxable);
        }

        return $out;
    }

    protected function legacyB2b(): array
    {
        $brid = $this->legacyBrid();
        $filter = $brid === null ? '' : ' AND c.branchid = '.(int) $brid;
        $rows = $this->legacy()->select(
            'SELECT c.pono, MAX(c.cartaddeddate) AS invdate, MIN(r.doorder) AS doorder, c.branchid, MAX(b.storename) AS storename,
                    MAX(b.address) AS branch_address, MAX(b.city) AS branch_city, MAX(b.phoneno) AS branch_phoneno,
                    MAX(b.gstno) AS branch_gstno, MAX(c.route) AS route, MAX(r.paymode) AS paymode, COUNT(*) AS item_count,
                    SUM(c.weight) AS total_weight, SUM(c.weight * c.purprice) AS total_value,
                    MAX(r.nettotal) AS billing_value,
                    MAX((SELECT COALESCE(SUM(x.aftercharges), 0) FROM tbl_br_purchase_cart x WHERE x.pono = c.pono AND x.cateid = ?)) AS cash_value,
                    SUM(c.weight * c.purprice * ((c.mcharge + c.wcharge) / 100)) AS total_charges
             FROM tbl_br_purchase_cart c
             INNER JOIN tbl_brorderrequest r ON r.transid = c.pono
             LEFT JOIN tbl_branches b ON b.brid = c.branchid
             WHERE r.status = ? AND c.cateid <> ? AND r.doorder BETWEEN ? AND ?'.$filter.'
             GROUP BY c.pono, c.branchid
             ORDER BY MIN(r.doorder) ASC, c.pono ASC',
            ['3', 'APPROVED', '3', $this->from, $this->to]);

        $out = [];
        $self = 0;
        foreach ($rows as $r) {
            $gst = strtoupper(trim((string) $r->branch_gstno));
            if ($gst === $this->gstin) {
                $self++;

                continue;
            }
            $mode = strtoupper(trim((string) $r->route)) ?: (strtoupper((string) $r->paymode) === 'REDEEM' ? 'RO' : 'BO');
            $billing = (float) $r->billing_value - (float) $r->cash_value;
            if ($billing <= 0) {
                continue;
            }
            $out[] = $this->b2bRow(substr((string) ($r->invdate ?: $r->doorder), 0, 10), (string) $r->pono, (string) $r->branchid,
                $r->storename, $r->branch_address, $r->branch_city, $r->branch_phoneno, $mode, $gst, (int) $r->item_count,
                $billing, (float) $r->total_weight, (float) $r->total_value, (float) $r->total_charges);
        }
        $this->noteSelf($self);

        return $out;
    }

    // ------------------------------------------------------------------ new system

    protected function newB2c(): array
    {
        $excluded = Plan::whereIn('code', self::EXCLUDED_PLAN_CODES)->pluck('id')->all();
        $q = DB::table('sales_invoices as s')
            ->leftJoin('bonds as b', 'b.invoice_no', '=', 's.invoice_no')
            ->leftJoin('sale_lines as l', 'l.invoice_id', '=', 's.id')
            ->leftJoin('members as m', 'm.id', '=', 's.customer_member_id')
            ->whereBetween('s.date', [$this->from, $this->to])
            ->where(fn ($w) => $w->whereNull('s.status')->orWhere('s.status', '<>', 'cancelled'))
            ->when($this->branchId, fn ($w) => $w->where('s.branch_id', $this->branchId),
                fn ($w) => $w->whereIn('s.branch_id', Branch::where('gst_no', $this->gstin)->pluck('id')))
            ->orderBy('s.date')->orderBy('s.id')
            ->select('s.id', 's.invoice_no', 's.date', 's.customer_name', 'b.plan_id', 'b.epin_value',
                'm.member_code', 'm.address', 'm.city', 'm.phone', 'm.pan', 'm.aadhaar',
                'l.qty', 'l.rate', 'l.making', 'l.wastage', 'l.line_total');

        $out = [];
        foreach ($q->get() as $r) {
            if ($r->plan_id && in_array((int) $r->plan_id, $excluded, true)) {
                continue;
            }
            $taxable = (float) $r->line_total;
            if (! ((float) $r->epin_value > 0 && $taxable > 0)) {
                continue;
            }
            $out[] = $this->b2cRow($r->date, (string) $r->invoice_no, (string) ($r->member_code ?? ''), $r->customer_name, $r->address,
                $r->city, $r->phone, $r->pan, $r->aadhaar, (float) $r->qty, (float) $r->rate, (float) $r->making + (float) $r->wastage, $taxable);
        }

        return $out;
    }

    protected function newB2b(): array
    {
        $supplied = ['approved', 'in_transit', 'delivered', 'billed'];
        $hq = Branch::where('gst_no', $this->gstin)->pluck('id')->all();
        $requests = DB::table('branch_order_requests as r')
            ->join('branches as br', 'br.id', '=', 'r.branch_id')
            ->whereIn('r.status', $supplied)
            ->whereBetween(DB::raw('DATE(COALESCE(r.approved_at, r.created_at))'), [$this->from, $this->to])
            ->when($this->branchId, fn ($w) => $w->where('r.branch_id', $this->branchId),
                fn ($w) => $w->where(fn ($ww) => $ww->whereIn('r.current_branch_id', $hq)->orWhereNull('r.current_branch_id')))
            ->orderByRaw('COALESCE(r.approved_at, r.created_at)')->orderBy('r.id')
            ->select('r.id', 'r.request_no', 'r.source', 'r.approved_at', 'r.created_at', 'r.grand_total',
                'br.id as buyer_id', 'br.name', 'br.address', 'br.city', 'br.phone', 'br.gst_no')
            ->get();
        if ($requests->isEmpty()) {
            return [];
        }
        $allLines = DB::table('branch_order_lines')->whereIn('order_request_id', $requests->pluck('id'))->get();
        $lines = $allLines->filter(fn ($l) => $l->material !== 'cash')->groupBy('order_request_id');
        $cash = $allLines->filter(fn ($l) => $l->material === 'cash')->groupBy('order_request_id')->map(fn ($g) => (float) $g->sum('line_total'));

        $out = [];
        $self = 0;
        foreach ($requests as $r) {
            $gst = strtoupper(trim((string) $r->gst_no));
            if ($gst === $this->gstin) {
                $self++;

                continue;
            }
            $ls = $lines[$r->id] ?? collect();
            if ($ls->isEmpty()) {
                continue;   // cash-only top-up: no goods supplied
            }
            [$weight, $value, $charges] = [0.0, 0.0, 0.0];
            foreach ($ls as $l) {
                $rate = (float) ($l->unit_price ?: $l->rate);
                $cross = (float) $l->weight * $rate;
                $weight += (float) $l->weight;
                $value += $cross;
                $charges += $cross * (((float) $l->making_charge_pct + (float) $l->wastage_charge_pct) / 100) + (float) $l->hallmark_charge;
            }
            $billing = (float) $r->grand_total - (float) ($cash[$r->id] ?? 0);
            if ($billing <= 0) {
                continue;
            }
            $date = substr((string) ($r->approved_at ?: $r->created_at), 0, 10);
            $out[] = $this->b2bRow($date, (string) $r->request_no, (string) $r->buyer_id, $r->name, $r->address, $r->city, $r->phone,
                $r->source === 'redemption' ? 'RO' : 'BO', $gst, $ls->count(), $billing, $weight, $value, $charges);
        }
        $this->noteSelf($self);

        return $out;
    }

    // ------------------------------------------------------------------ row shapes (legacy arithmetic)

    protected function b2cRow($date, string $ref, string $cusId, $name, $address, $city, $phone, $pan, $aadhaar,
        float $weight, float $rate, float $chargePct, float $taxable): array
    {
        $net = $weight * $rate;
        $charges = $net * ($chargePct / 100);
        $gst = $taxable * self::GST_PCT / 100;

        return array_combine(self::B2C_COLUMNS, [
            null, $this->date($date), $ref, $cusId, $this->text($name), $this->text($address), $this->text($city), $this->text($phone),
            $this->text($pan), $this->text($aadhaar), 'BTOC', 'NA', self::B2C_ITEM, self::HSN, 1,
            round($taxable + $gst, 2), round($weight, 2), $rate, round($net, 2), round($charges, 2),
            round($taxable, 2), round($gst, 2), round($gst / 2, 2), round($gst / 2, 2),
        ]);
    }

    /**
     * B2B rows are tax-reversed from the billed amount: the order header is what the dealer was
     * billed (for redemption restocks it is the customer's original redeemed value, which differs
     * from the cart priced at the restock-day rate), so TAXABLE = billing ÷ 1.03 and GST = the
     * remainder, then NET / CHARGES split the taxable amount by the cart's charge percentage.
     * The auditor imports taxable + GST, and those must add back to BILLING VALUE.
     */
    protected function b2bRow($date, string $ref, string $cusId, $name, $address, $city, $phone, string $mode, string $gstin,
        int $qty, float $billing, float $weight, float $value, float $charges): array
    {
        $billing = round($billing, 2);
        $taxable = round($billing / (1 + self::GST_PCT / 100), 2);
        $gst = round($billing - $taxable, 2);
        $sgst = round($gst / 2, 2);
        $cgst = round($gst - $sgst, 2);
        $chargePct = $value > 0 ? $charges / $value : self::B2B_DEFAULT_CHARGE_PCT / 100;
        $net = round($taxable / (1 + $chargePct), 2);
        $rate = $weight > 0 ? round($net / $weight, 2) : 0.0;

        return array_combine(self::B2B_COLUMNS, [
            null, $this->date($date), $ref, $cusId, $this->text($name), $this->text($address), $this->text($city), $this->text($phone), $mode, $gstin,
            self::B2B_ITEM, self::HSN, $qty, $billing, round($weight, 2), $rate, $net,
            round($taxable - $net, 2), $taxable, $gst, $sgst, $cgst,
        ]);
    }

    protected function date($d): Carbon
    {
        return Carbon::parse(substr((string) $d, 0, 10))->startOfDay();
    }

    /** One-line text the way the auditor's sheet shows it (legacy addresses carry line breaks and padding). */
    protected function text($v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    }

    protected function noteSelf(int $self): void
    {
        if ($self > 0) {
            $this->notes[] = __(':n approved order(s) were left out of B2B because the buyer branch carries the filing GSTIN :gstin itself (not a B2B supply — fix the branch record if it is really a dealer).',
                ['n' => $self, 'gstin' => $this->gstin]);
        }
    }
}
