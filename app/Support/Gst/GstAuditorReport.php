<?php

namespace App\Support\Gst;

use App\Models\Branch;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Auditor-format GST registers — REDEEMED INVOICES ONLY (board, 2026-11-11).
 *
 * GST on gold / silver is raised when the metal is handed over, i.e. on the redemption
 * invoice (legacy tbl_qrsalesinvoice + tbl_salesqr_cart, new redemption_invoices + lines),
 * never on the scheme purchase bill. One row per redemption invoice:
 *
 *  - B2C  = redemptions at a branch that carries the filing GSTIN (LORD JEWELLER
 *    33AREPB3962B3ZD — HQ and the merged "LORD JEWELLER" records): the consumer is the
 *    buyer, BILL MODE BTOC, no GST number.
 *  - B2B  = redemptions at a dealer branch (sub dealer / retailer / area / wholesaler …):
 *    the dealer requested the stock and redeemed it for the customer, so the supply is
 *    billed to the dealer — buyer = the dealer branch with its own GSTIN, BILL MODE RO.
 *
 * Amounts come from the redemption invoice itself (taxable, CGST, SGST) so BILLING VALUE
 * = TAXABLE + GST exactly, as the auditor files them; NET / CHARGES split the taxable
 * amount by the cart lines (piece price × pieces vs making + wastage). GOLD WEIGHT is the
 * grams redeemed (pieces × the piece's gram size); silver pieces are flagged in the item
 * description and carried separately into the GSTR-1 HSN sheet.
 *
 * Both registers get a running INV NO that continues the auditor's own sequence
 * (B2C …2216 → 2217, B2B …5270 → 5271 after the September 2026 filing) and carry the
 * redemption invoice number as REF NO. The sequence is remembered per register in
 * settings (group gst_auditor) so the next month continues automatically and
 * re-exporting a month reuses its own start number.
 *
 * Source: "legacy" reads the Lord DB (lordicl) for pre-go-live months; "new" reads the
 * new system's redemption_invoices / redemption_lines (imported + native).
 */
class GstAuditorReport
{
    public const SOURCE_LEGACY = 'legacy';

    public const SOURCE_NEW = 'new';

    public const SETTINGS_GROUP = 'gst_auditor';

    /** Gold pieces — the HSN the auditor has been filing under. */
    public const HSN = '71181000';

    /** Silver pieces — the catalog's HSN for silver products. */
    public const HSN_SILVER = '71189000';

    public const GST_PCT = 3.0;

    public const ITEM_GOLD = 'BIS 916 HUID GOLD';

    public const ITEM_SILVER = 'SILVER 999';

    /** Hidden per-material split (grams / taxable / gst) carried on each row for the GSTR-1 HSN sheet. */
    public const HSN_KEY = '_hsn';

    /** Where the auditor's numbering stood after the September 2026 filing. */
    public const FIRST_NUMBER = ['b2c' => 2217, 'b2b' => 5271];

    public const B2C_COLUMNS = ['INV NO', 'INV DATE', 'REF NO', 'CUSID', 'CUSNAME', 'ADDRESS', 'CITY', 'PHONE', 'PAN', 'AADHAR',
        'BILL MODE', 'GST NUMBER', 'ITEM DESCRIPTION', 'HSN CODE', 'QTY', 'BILLING VALUE', 'GOLD WEIGHT(GM)', 'GOLD RATE/G',
        'NET VALUE', 'CHARGES  (MC(2.5%)+WC(2.5%))', 'TAXABLE AMOUNT', 'GST (3%)', 'SGST', 'CGST'];

    public const B2B_COLUMNS = ['INV NO', 'INV DATE', 'REF NO', 'CUSID', 'CUSNAME', 'ADDRESS', 'CITY', 'CONTACT', 'BILL MODE',
        'GST NUMBER', 'ITEM DESCRIPTION', 'HSN CODE', 'QTY', 'BILLING VALUE', 'GOLD WEIGHT(GM)', 'ITEM RATE/G', 'NET VALUE',
        'CHARGES  (MC(2.5%)+WC(2.5%))', 'TAXABLE AMOUNT', 'GST (3%)', 'SGST', 'CGST'];

    /** Distributor's purchase register (legacy "GSTR PURCHASE"): what HQ billed the dealer for its redemptions. */
    public const PURCHASE_COLUMNS = ['SELLER', 'GSTN', 'PO NO', 'REF NO', 'PO DATE', 'ITEM DESCRIPTION', 'HSN CODE', 'QTY',
        'BILLING VALUE', 'GOLD WEIGHT(GM)', 'GOLD RATE/G', 'NET VALUE', 'CHARGES  (MC(2.5%)+WC(2.5%))', 'TAXABLE AMOUNT',
        'GST (3%)', 'SGST', 'CGST'];

    /** @return array<int, string> the column list of a register name (b2c | b2b | sales | purchase) */
    public static function columns(string $register): array
    {
        return match ($register) {
            'b2b' => self::B2B_COLUMNS,
            'purchase' => self::PURCHASE_COLUMNS,
            default => self::B2C_COLUMNS,
        };
    }

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

    /** The HQ branch (first branch carrying the filing GSTIN) — the seller on a distributor's purchase register. */
    public static function filingBranchName(?string $gstin = null): string
    {
        $gstin = $gstin ?: self::filingGstin();

        return (string) (Branch::query()->orderBy('id')->where('gst_no', $gstin)->value('name') ?: 'LORD JEWELLER');
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
        return array_map(fn ($r) => $this->row('b2c', $r), $this->redemptions(true));
    }

    /** @return array<int, array<string, mixed>> keyed by the B2B column names (INV NO left null) */
    public function b2bRows(): array
    {
        return array_map(fn ($r) => $this->row('b2b', $r), $this->redemptions(false));
    }

    /**
     * Distributor's own sales register (legacy dealer "GSTR SALES B TO C"): every redemption at the
     * dealer's branch, consumer as buyer, INV NO = the dealer's redemption invoice no (no auditor
     * sequence), REF NO = the sale invoice the QR came from. Needs $branchId.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dealerSalesRows(): array
    {
        if (! $this->branchId) {
            return [];
        }

        return array_map(function ($r) {
            $row = $this->row('b2c', $r);
            $row['INV NO'] = $r['ref'];
            $row['REF NO'] = $r['source_ref'];

            return $row;
        }, $this->redemptions(null));
    }

    /**
     * Distributor's purchase register (legacy dealer "GSTR PURCHASE"): the same redemptions as
     * HQ billed them to the dealer — seller = the filing branch. Needs $branchId.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dealerPurchaseRows(): array
    {
        if (! $this->branchId) {
            return [];
        }
        $seller = self::filingBranchName($this->gstin);

        return array_map(fn ($r) => $this->row('purchase', $r, $seller), $this->redemptions(null));
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

    /**
     * Redemption invoices of the period, normalised across both sources.
     * $own = true → branches carrying the filing GSTIN (B2C); false → every other branch (B2B);
     * null → no GSTIN test (a distributor's own branch, with $branchId).
     *
     * @return array<int, array{ref:string,source_ref:string,date:string,gold_rate:float,taxable:float,cgst:float,sgst:float,
     *     branch:array{id:string,name:?string,address:?string,city:?string,phone:?string,gstin:string},
     *     customer:array{id:string,name:?string,address:?string,city:?string,phone:?string,pan:?string,aadhaar:?string},
     *     lines:array<int, array{material:string,pieces:float,grams:float,net:float,taxable:float}>}>
     */
    protected function redemptions(?bool $own): array
    {
        return $this->source === self::SOURCE_LEGACY ? $this->legacyRedemptions($own) : $this->newRedemptions($own);
    }

    /** SQL fragment for the GSTIN test (empty when $own is null). */
    protected function gstinClause(?bool $own, string $expr): string
    {
        return $own === null ? '' : ' AND UPPER(TRIM(COALESCE('.$expr.", ''))) ".($own ? '=' : '<>').' ?';
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

    protected function legacyRedemptions(?bool $own): array
    {
        $brid = $this->legacyBrid();
        if ($brid === 0) {
            return [];
        }
        $rows = $this->legacy()->select(
            'SELECT q.estid, q.estdate, q.invno, q.cusid, q.cusname, q.cusaddress, q.cucity, q.cucontact, q.cupan, q.cuaadhar,
                    q.netttotal, q.cgst, q.sgst, q.goldrate, q.branchid,
                    b.storename, b.address AS br_address, b.city AS br_city, b.phoneno AS br_phone, b.gstno
             FROM tbl_qrsalesinvoice q
             LEFT JOIN tbl_branches b ON b.brid = q.branchid
             WHERE q.estdate BETWEEN ? AND ?'
            .$this->gstinClause($own, 'b.gstno')
            .($brid ? ' AND q.branchid = ?' : '').'
             ORDER BY q.estdate ASC, q.estid ASC',
            array_merge([$this->from, $this->to], $own === null ? [] : [$this->gstin], $brid ? [$brid] : []));
        if (! $rows) {
            return [];
        }

        $carts = $this->legacy()->table('tbl_salesqr_cart')
            ->whereIn('invno', array_values(array_unique(array_map(fn ($r) => (string) $r->invno, $rows))))
            ->get(['invno', 'cartaddeddate', 'catename', 'prname', 'weight', 'purprice', 'aftercharges'])
            ->groupBy('invno');

        $out = [];
        $noLines = 0;
        foreach ($rows as $r) {
            if (trim((string) $r->cusid) === 'LORD JEWELLER') {
                continue;
            }
            $cart = $carts->get((string) $r->invno) ?? collect();
            // several redemptions can hang off one sale: take the lines dated the redemption day,
            // else the whole cart when it adds up to this invoice, else nothing (header amounts only)
            $lines = $cart->filter(fn ($c) => (string) $c->cartaddeddate === (string) $r->estdate);
            if ($lines->isEmpty() && $cart->isNotEmpty() && abs((float) $cart->sum('aftercharges') - (float) $r->netttotal) < 0.05) {
                $lines = $cart;
            }
            if ($lines->isEmpty()) {
                $noLines++;
            }
            $out[] = [
                'ref' => (string) $r->estid,
                'source_ref' => (string) $r->invno,
                'date' => (string) $r->estdate,
                'gold_rate' => (float) $r->goldrate,
                'taxable' => (float) $r->netttotal,
                'cgst' => (float) $r->cgst,
                'sgst' => (float) $r->sgst,
                'branch' => ['id' => (string) $r->branchid, 'name' => $r->storename, 'address' => $r->br_address, 'city' => $r->br_city,
                    'phone' => $r->br_phone, 'gstin' => strtoupper(trim((string) $r->gstno))],
                'customer' => ['id' => (string) $r->cusid, 'name' => $r->cusname, 'address' => $r->cusaddress, 'city' => $r->cucity,
                    'phone' => $r->cucontact, 'pan' => $r->cupan, 'aadhaar' => $r->cuaadhar],
                'lines' => $lines->map(function ($c) {
                    $pieces = (float) $c->weight;

                    return [
                        'material' => strtolower(trim((string) $c->catename)) === 'silver' ? 'silver' : 'gold',
                        'pieces' => $pieces,
                        'grams' => $pieces * self::gramsPerPiece((string) $c->prname),
                        'net' => $pieces * (float) $c->purprice,
                        'taxable' => (float) $c->aftercharges,
                    ];
                })->values()->all(),
            ];
        }
        $this->noteNoLines($noLines);

        return $out;
    }

    // ------------------------------------------------------------------ new system

    protected function newRedemptions(?bool $own): array
    {
        $invoices = DB::table('redemption_invoices as i')
            ->join('branches as br', 'br.id', '=', 'i.branch_id')
            ->leftJoin('members as m', 'm.id', '=', 'i.member_id')
            ->whereBetween('i.invoice_date', [$this->from, $this->to])
            ->when($own !== null, fn ($w) => $w->whereRaw(substr($this->gstinClause($own, 'br.gst_no'), 5), [$this->gstin]))
            ->when($this->branchId, fn ($w) => $w->where('i.branch_id', $this->branchId))
            ->orderBy('i.invoice_date')->orderBy('i.id')
            ->select('i.id', 'i.invoice_no', 'i.invoice_date', 'i.reference_no', 'i.referrer_name', 'i.legacy_restock', 'i.taxable_total', 'i.cgst', 'i.sgst', 'i.gold_rate',
                'br.id as br_id', 'br.legacy_brid', 'br.name as br_name', 'br.address as br_address', 'br.city as br_city', 'br.phone as br_phone', 'br.gst_no',
                'm.member_code', 'm.name as m_name', 'm.address', 'm.city', 'm.phone', 'm.pan', 'm.aadhaar')
            ->get();
        if ($invoices->isEmpty()) {
            return [];
        }
        $lines = DB::table('redemption_lines as l')
            ->leftJoin('catalog_products as p', 'p.id', '=', 'l.catalog_product_id')
            ->whereIn('l.redemption_invoice_id', $invoices->pluck('id'))
            ->select('l.*', 'p.default_weight', 'p.making_charge_pct', 'p.wastage_charge_pct', 'p.material as p_material')
            ->get()->groupBy('redemption_invoice_id');

        $out = [];
        $noLines = 0;
        foreach ($invoices as $r) {
            $ls = [];
            foreach ($lines[$r->id] ?? [] as $l) {
                $material = strtolower((string) ($l->material ?: $l->p_material));
                if ($material === 'cash') {
                    continue;
                }
                if ($r->legacy_restock !== null) {
                    // imported from the Lord DB: unit_weight = pieces, amount = taxable incl. charges, no piece price
                    $pieces = (float) $l->unit_weight;
                    $perPiece = self::gramsPerPiece((string) $l->description) ?: (float) $l->default_weight;
                    $taxable = (float) $l->amount;
                    $pct = ((float) $l->making_charge_pct + (float) $l->wastage_charge_pct) / 100;
                    $net = round($taxable / (1 + $pct), 2);
                } else {
                    // native: quantity pieces × unit_weight g, amount = pieces × piece price, making / wastage in ₹
                    $pieces = (float) $l->quantity;
                    $perPiece = (float) $l->unit_weight ?: self::gramsPerPiece((string) $l->description);
                    $net = (float) $l->amount;
                    $taxable = $net + (float) $l->making + (float) $l->wastage;
                }
                $ls[] = ['material' => $material === 'silver' ? 'silver' : 'gold', 'pieces' => $pieces, 'grams' => $pieces * $perPiece,
                    'net' => $net, 'taxable' => $taxable];
            }
            if (! $ls) {
                $noLines++;
            }
            $out[] = [
                'ref' => (string) $r->invoice_no,
                'source_ref' => (string) ($r->reference_no ?? ''),
                'date' => substr((string) $r->invoice_date, 0, 10),
                'gold_rate' => (float) $r->gold_rate,
                'taxable' => (float) $r->taxable_total,
                'cgst' => (float) $r->cgst,
                'sgst' => (float) $r->sgst,
                'branch' => ['id' => (string) ($r->legacy_brid ?: $r->br_id), 'name' => $r->br_name, 'address' => $r->br_address, 'city' => $r->br_city,
                    'phone' => $r->br_phone, 'gstin' => strtoupper(trim((string) $r->gst_no))],
                'customer' => ['id' => (string) ($r->member_code ?? ''), 'name' => $r->m_name ?? $r->referrer_name, 'address' => $r->address,
                    'city' => $r->city, 'phone' => $r->phone, 'pan' => $r->pan, 'aadhaar' => $r->aadhaar],
                'lines' => $ls,
            ];
        }
        $this->noteNoLines($noLines);

        return $out;
    }

    // ------------------------------------------------------------------ row shape

    /**
     * One register row from a normalised redemption invoice. BILLING VALUE = TAXABLE + GST exactly.
     * $register: b2c (consumer columns) | b2b (dealer as buyer) | purchase (distributor's input side, $seller = HQ).
     */
    protected function row(string $register, array $r, string $seller = ''): array
    {
        $taxable = round($r['taxable'], 2);
        $gst = round($r['cgst'] + $r['sgst'], 2);
        $sgst = round($gst / 2, 2);
        $cgst = round($gst - $sgst, 2);
        $billing = round($taxable + $gst, 2);

        $lines = $r['lines'];
        $pieces = array_sum(array_column($lines, 'pieces'));
        $qty = $pieces > 0 ? (int) round($pieces) : 1;
        $net = $lines ? round(array_sum(array_column($lines, 'net')), 2) : $taxable;
        $charges = round($taxable - $net, 2);

        // per-material split, scaled to the invoice's taxable amount so the HSN sheet agrees with the registers
        $split = [];
        $lineTaxable = array_sum(array_column($lines, 'taxable'));
        foreach ($lines as $l) {
            $m = $l['material'];
            $share = $lineTaxable > 0 ? $taxable * $l['taxable'] / $lineTaxable : 0.0;
            $split[$m]['grams'] = ($split[$m]['grams'] ?? 0) + $l['grams'];
            $split[$m]['taxable'] = ($split[$m]['taxable'] ?? 0) + $share;
        }
        if (! $split) {
            $split['gold'] = ['grams' => 0.0, 'taxable' => $taxable];
        }
        foreach ($split as $m => &$s) {
            $s['taxable'] = round($s['taxable'], 2);
            $s['gst'] = round($s['taxable'] * self::GST_PCT / 100, 2);
            $s['grams'] = round($s['grams'], 3);
        }
        unset($s);
        $goldGrams = $split['gold']['grams'] ?? 0.0;
        $silverGrams = $split['silver']['grams'] ?? 0.0;
        $item = match (true) {
            isset($split['gold']) && isset($split['silver']) => self::ITEM_GOLD.' + '.self::ITEM_SILVER.' '.rtrim(rtrim(number_format($silverGrams, 3, '.', ''), '0'), '.').' g',
            isset($split['silver']) => self::ITEM_SILVER,
            default => self::ITEM_GOLD,
        };
        $hsn = isset($split['gold']) ? self::HSN : self::HSN_SILVER;

        $b = $r['branch'];
        $c = $r['customer'];
        $head = [null, $this->date($r['date']), $r['ref']];
        $money = [$billing, round($goldGrams, 3), round($r['gold_rate'], 2), $net, $charges, $taxable, $gst, $sgst, $cgst];
        $row = match ($register) {
            'b2b' => array_combine(self::B2B_COLUMNS, [...$head, $b['id'], $this->text($b['name']), $this->text($b['address']), $this->text($b['city']),
                $this->text($b['phone']), 'RO', $b['gstin'], $item, $hsn, $qty, ...$money]),
            'purchase' => array_combine(self::PURCHASE_COLUMNS, [$seller, $this->gstin, $r['ref'], $r['source_ref'], $this->date($r['date']),
                $item, $hsn, $qty, ...$money]),
            default => array_combine(self::B2C_COLUMNS, [...$head, $c['id'], $this->text($c['name']), $this->text($c['address']), $this->text($c['city']),
                $this->text($c['phone']), $this->text($c['pan']), $this->text($c['aadhaar']), 'BTOC', 'NA', $item, $hsn, $qty, ...$money]),
        };
        $row[self::HSN_KEY] = $split;

        return $row;
    }

    /** "100 mg Gold" → 0.1, "2 g Gold" → 2.0, "5 g Silver" → 5.0; 0 when the label carries no size. */
    public static function gramsPerPiece(string $label): float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(mg|g)\b/i', $label, $m)) {
            return strtolower($m[2]) === 'mg' ? (float) $m[1] / 1000 : (float) $m[1];
        }

        return 0.0;
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

    protected function noteNoLines(int $n): void
    {
        if ($n > 0) {
            $this->notes[] = __(':n redemption invoice(s) have no cart lines on record — their gold weight is shown as 0 and the whole taxable amount as net value.', ['n' => $n]);
        }
    }
}
