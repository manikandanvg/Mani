<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'branch';

    use SoftDeletes;

    protected $guarded = [];

    /**
     * Dealership ladder (board 2026-08-26) — the Plans `hid` order, top → bottom:
     *   0 hq · 1 regional · 2 zonal · 3 district · 4 taluk · 5 reseller (G5 Retailer) ·
     *   6 sub_dealer · 7 wholesaler (G24) · 8 area_dealer.
     * HQ → … → Taluka is the distributor chain; Taluka is its last node — the offline
     * showroom branch that runs the L-BOX. Below it sits the retail tier.
     */
    public const LEVELS = ['hq', 'regional', 'zonal', 'district', 'taluk', 'reseller', 'sub_dealer', 'wholesaler', 'area_dealer'];

    public const LEVEL_LABELS = [
        'hq' => 'Lord (HQ / Super-admin)',
        'regional' => 'Regional Dealership',
        'zonal' => 'Zonal Dealership',
        'district' => 'District Dealership',
        'taluk' => 'Taluka Dealership (showroom / L-BOX)',
        'reseller' => 'G5 Retailer',
        'sub_dealer' => 'Sub Dealer',
        'wholesaler' => 'G24 Wholesaler',
        'area_dealer' => 'Area Distributor',
    ];

    /**
     * Board resolution 2026-10-24: every dealership is either an independent DEALER or one of
     * LORD'S BRANCHES (the dealer becomes the branch in-charge; by TBP stage they move onto
     * the payroll). Schemes may be limited to one kind (Plan::available_to).
     */
    /**
     * Board 2026-11-02/03: only dealers of the HQ chain (regional → taluka) and wholesalers may
     * show their branch payment QR to receive payments; retailers (reseller), area dealers and
     * sub dealers may not.
     */
    public const NO_PAYMENT_QR_LEVELS = ['reseller', 'area_dealer', 'sub_dealer'];

    /** Admin override on the branch form (user 2026-11-03): null = by level, on, off. */
    public const STATIC_QR_OPTIONS = ['on' => 'Enabled', 'off' => 'Disabled'];

    public function showsPaymentQr(): bool
    {
        return match ((string) $this->static_qr) {
            'on' => true,
            'off' => false,
            default => ! in_array((string) $this->level, self::NO_PAYMENT_QR_LEVELS, true),
        };
    }

    public const KIND_DEALER = 'dealer';
    public const KIND_BRANCH = 'branch';
    public const KINDS = [self::KIND_DEALER, self::KIND_BRANCH];
    public const KIND_LABELS = [
        self::KIND_DEALER => 'Dealer (independent dealership)',
        self::KIND_BRANCH => "Lord's Branch (company branch · dealer is the in-charge)",
    ];

    public static function kindLabel(?string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? ($kind ? ucfirst($kind) : '—');
    }

    /** True for HQ and every converted "Lord's Branch"; false for an independent dealer. */
    /** upi://pay deep link for this branch's static QR (null when no UPI id is set). */
    public function upiLink(): ?string
    {
        if (blank($this->upi_id)) {
            return null;
        }

        return 'upi://pay?pa=' . rawurlencode(trim((string) $this->upi_id))
            . '&pn=' . rawurlencode(mb_substr(trim((string) $this->name), 0, 40)) . '&cu=INR';
    }

    /**
     * Board 2026-10-31: the branch payment QR IS the L-BOX static QR — the same QR the app's
     * Wallet → Withdraw at branch screen scans. Launching without boxes, every dealer gets a
     * virtual "static QR" device (board_type qr) so the existing app flow works unchanged.
     */
    public function staticQrDevice(): ?Device
    {
        return Device::where('branch_id', $this->id)->where('status', 'active')
            ->orderByRaw("CASE WHEN board_type = 'qr' THEN 1 ELSE 0 END")   // a real box wins
            ->orderBy('id')->first();
    }

    /** Create the virtual static-QR device for this branch if it has no active device yet. */
    public function ensureStaticQr(): Device
    {
        return $this->staticQrDevice() ?? Device::create([
            'name' => trim((string) $this->name) . ' — payment QR',
            'serial_no' => 'QR-' . $this->id,
            'board_type' => Device::BOARD_STATIC_QR,
            'branch_id' => $this->id,
            'status' => 'active',
            'registered_at' => now(),
            'notes' => 'Static payment QR (no L-BOX) — board 2026-10-31',
        ]);
    }

    /** PNG of the branch's static QR (device uuid), or null when none was generated. */
    public function staticQrUrl(): ?string
    {
        $device = $this->staticQrDevice();

        return $device ? app(\App\Services\Qr\QrCodeService::class)->store($device->uuid, 'lbox-' . $device->serial_no) : null;
    }

    /** The static QR first; else the uploaded image; else a generated QR of the UPI link. */
    public function paymentQrUrl(): ?string
    {
        if ($url = $this->staticQrUrl()) {
            return $url;
        }
        if (filled($this->payment_qr_path)) {
            return media_url($this->payment_qr_path);
        }

        return $this->upiLink() ? route('branch.payment-qr', $this) : null;
    }

    public function isLordBranch(): bool
    {
        return $this->kind === self::KIND_BRANCH || $this->level === 'hq';
    }

    /** The kind used for scheme availability checks (HQ always counts as a Lord's Branch). */
    public function kindForSchemes(): string
    {
        return $this->isLordBranch() ? self::KIND_BRANCH : self::KIND_DEALER;
    }

    /** Plans.hid → branch level (0 = HQ itself, never assigned to a dealer). */
    public const HID_LEVELS = [1 => 'regional', 2 => 'zonal', 3 => 'district', 4 => 'taluk', 5 => 'reseller', 6 => 'sub_dealer', 7 => 'wholesaler', 8 => 'area_dealer'];

    /**
     * Who each level may order stock FROM — the board's FINAL matrix (2026-08-26):
     *
     *   Buyer            HQ  Regional Zonal District Taluka Retailer Wholesaler
     *   1 Regional        ✓
     *   2 Zonal           ✓    ✓
     *   3 District        ✓    ✓       ✓
     *   4 Taluka          ✓    ✓       ✓      ✓
     *   5 G5 Retailer     ✓    ✓       ✓      ✓        ✓
     *   6 Sub Dealer      ✓                            ✓       ✓        ✓
     *   7 G24 Wholesaler  ✓                            ✓       ✓
     *   8 Area Distrib.   ✓    ✓       ✓      ✓        ✓       ✓        ✓
     */
    public const ALLOWED_SOURCES = [
        'regional' => ['hq'],
        'zonal' => ['hq', 'regional'],
        'district' => ['hq', 'regional', 'zonal'],
        'taluk' => ['hq', 'regional', 'zonal', 'district'],
        'reseller' => ['hq', 'regional', 'zonal', 'district', 'taluk'],
        'sub_dealer' => ['hq', 'taluk', 'reseller', 'wholesaler'],
        'wholesaler' => ['hq', 'taluk', 'reseller'],
        'area_dealer' => ['hq', 'regional', 'zonal', 'district', 'taluk', 'reseller', 'wholesaler'],
    ];

    /** Seller levels that earn a stock-transfer margin (HQ earns none; Sub Dealer / Area Distributor never sell on). */
    public const SELLER_LEVELS = ['regional', 'zonal', 'district', 'taluk', 'reseller', 'wholesaler'];

    public static function levelLabel(?string $level): string
    {
        return self::LEVEL_LABELS[$level] ?? ($level ? ucfirst(str_replace('_', ' ', $level)) : '—');
    }

    /** Branch level a dealership plan confers, from its hid (null for non-dealership plans). */
    public static function levelForHid(int|string|null $hid): ?string
    {
        return $hid === null || $hid === '' ? null : (self::HID_LEVELS[(int) $hid] ?? null);
    }

    /** Allowed source levels for a level; unknown / unset level → the levels above it (legacy rule). */
    public static function allowedSourceLevels(?string $level): array
    {
        if (isset(self::ALLOWED_SOURCES[$level])) {
            return self::ALLOWED_SOURCES[$level];
        }
        $own = static::levelIndex($level);

        return array_values(array_filter(array_slice(self::LEVELS, 0, $own), fn ($l) => $l === 'hq' || in_array($l, self::SELLER_LEVELS, true)));
    }

    protected $casts = [
        'order_limit' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'bill_margin' => 'decimal:3',
        'digi_cash_balance' => 'decimal:2',
        'gold_gm_margin' => 'decimal:2',
        'silver_gm_margin' => 'decimal:2',
        'stock_trans_margin' => 'decimal:3',
        'vat_pct' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    /**
     * INR per 1 unit of this branch's operating currency, read now (callers freeze it
     * onto the document). INR base → 1.0; otherwise the inverse of currencies.rate_to_base
     * (which is "currency units per 1 INR").
     */
    public function fxRateToBase(): float
    {
        $code = strtoupper($this->currency_code ?: 'INR');
        $base = strtoupper(\App\Support\Money::base()?->code ?? 'INR');
        if ($code === $base) {
            return 1.0;
        }
        $perInr = (float) Currency::where('code', $code)->value('rate_to_base');
        if ($perInr <= 0) {
            // Fail LOUDLY: a silent 1.0 here would freeze fx=1.0 onto invoices,
            // ledgers and CBC/RD entries — mispricing everything at this branch
            // by the whole fx factor with no visible symptom.
            throw new \RuntimeException(
                "No usable exchange rate for {$code}: currencies.rate_to_base must be set > 0 before this branch can transact."
            );
        }

        return round(1 / $perInr, 6);
    }

    public function currencySymbol(): string
    {
        return \App\Support\Money::currency($this->currency_code ?: 'INR')?->symbol ?? '';
    }

    /**
     * Tax structure for a sale at this branch. GST splits the per-line GST into CGST/SGST
     * (India); VAT applies vat_pct to the taxable base; none is tax-free.
     *
     * @return array{mode:string,total:float,cgst:float,sgst:float}
     */
    public function taxOn(float $taxable, float $gstFromLines): array
    {
        return match ($this->tax_regime) {
            'vat' => ['mode' => 'vat', 'total' => round($taxable * (float) $this->vat_pct / 100, 2), 'cgst' => 0.0, 'sgst' => 0.0],
            'none' => ['mode' => 'none', 'total' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0],
            default => ['mode' => 'gst', 'total' => round($gstFromLines, 2), 'cgst' => round($gstFromLines / 2, 2), 'sgst' => round($gstFromLines / 2, 2)],
        };
    }

    /** Legacy ci_users.desiname → dealership level (null when the designation is blank / unknown). */
    public static function levelFromDesignation(?string $designation): ?string
    {
        return match (mb_strtolower(trim((string) $designation))) {
            'reseller' => 'reseller', 'taluk', 'taluka' => 'taluk', 'zonal' => 'zonal', 'district' => 'district',
            'regional' => 'regional', 'wholesaler' => 'wholesaler', 'area dealer', 'area distributor' => 'area_dealer',
            'sub dealer' => 'sub_dealer', default => null,
        };
    }

    /**
     * Dropdown label that tells 1,180 "LORD JEWELLER" rows apart (user 2026-11-02):
     * "NAME (city / phone / in-charge)" — blank parts are dropped.
     */
    public function pickLabel(): string
    {
        $parts = array_filter([trim((string) $this->city), trim((string) $this->phone), trim((string) $this->incharge)]);

        return trim((string) $this->name) . ($parts ? ' (' . implode(' / ', $parts) . ')' : '');
    }

    /** Options for a searchable branch select: matches name, city, phone or in-charge. */
    public static function pickOptions(?string $search = null, int $limit = 50): array
    {
        $q = static::query()->orderBy('name')->orderBy('city');
        if (filled($search)) {
            $like = '%' . trim($search) . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('city', 'like', $like)
                ->orWhere('phone', 'like', $like)->orWhere('incharge', 'like', $like));
        }

        return $q->limit($limit)->get()->mapWithKeys(fn (self $b) => [$b->id => $b->pickLabel()])->all();
    }

    public function members() { return $this->hasMany(Member::class); }

    public function users() { return $this->hasMany(User::class); }

    /** The branch one hop up the chain that this branch orders stock from. */
    public function sourceBranch() { return $this->belongsTo(Branch::class, 'source_branch_id'); }

    /** Branches that source their stock from this branch (one hop down). */
    public function childBranches() { return $this->hasMany(Branch::class, 'source_branch_id'); }

    /**
     * Is this branch a VALID supplier of $buyer — its recorded source AND at a level the
     * board's buys-from matrix allows for the buyer's level? (Complaint 2026-11-11: the
     * legacy import wrote each dealer's SPONSOR as its "supplier", so 250 of the 326 links
     * break the matrix — an Area Distributor "supplying" another, etc. Those links confer
     * nothing: no approval right, no stock shipped from the sponsor.)
     */
    public function maySupply(?Branch $buyer): bool
    {
        if (static::hqOnlySourcing()) {
            return false;   // user 2026-11-11: every branch orders from Head Office until confirmed
        }

        return $buyer !== null
            && (int) $buyer->source_branch_id === (int) $this->getKey()
            && in_array((string) $this->level, static::allowedSourceLevels($buyer->level), true);
    }

    /** User 2026-11-11: all branches source from Head Office only (config features.hq_only_sourcing). */
    public static function hqOnlySourcing(): bool
    {
        return (bool) config('features.hq_only_sourcing', true);
    }

    /** The branch that fulfils this branch's stock orders: its valid supplier, else null (= Head Office adds the stock). */
    public function orderSupplier(): ?Branch
    {
        $source = $this->sourceBranch;

        return $source && $source->maySupply($this) ? $source : null;
    }

    /** Position of a level in the top→bottom chain; unknown/null sorts to the bottom. */
    public static function levelIndex(?string $level): int
    {
        $i = array_search($level, self::LEVELS, true);

        return $i === false ? count(self::LEVELS) : $i;
    }

    /**
     * Branches that may act as THIS branch's supplier (order source): active branches at
     * the levels in this level's allow-list (ALLOWED_SOURCES). Every level may buy from HQ
     * in the final matrix; $hqOverride is kept so the HQ Branch form always offers Head
     * Office even for a level whose list might later drop it.
     */
    public function sourceCandidates(bool $hqOverride = false)
    {
        $levels = static::allowedSourceLevels($this->level);
        if ($hqOverride && ! in_array('hq', $levels, true)) {
            $levels[] = 'hq';
        }

        return static::query()
            ->whereKeyNot($this->getKey())
            ->whereIn('level', $levels)
            ->where('is_active', true)
            ->orderBy('name');
    }

    /**
     * The distributor (semi-admin) login that operates this branch — the legacy
     * ci_users row keyed by brid. Used by the "View as Dealer" impersonation action.
     */
    public function distributorUser()
    {
        return $this->hasOne(User::class)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', 'distributor'));
    }

    protected static function booted(): void
    {
        // HQ-only sourcing (user 2026-11-11): a branch can never be saved pointing at another
        // dealer — whatever screen or command tried, the supplier stays Head Office.
        static::saving(function (Branch $branch) {
            if ($branch->source_branch_id && static::hqOnlySourcing()
                && static::whereKey($branch->source_branch_id)->value('level') !== 'hq') {
                $branch->source_branch_id = null;
            }
        });

        // When a branch is saved without coordinates, geocode from its address so it
        // appears on the store map. Best-effort: silent on failure, skipped in tests.
        static::saved(function (Branch $branch) {
            if (app()->runningUnitTests()) {
                return;
            }
            $missing = blank($branch->latitude) || (float) $branch->latitude == 0.0;
            $hasAddress = filled($branch->address) || filled($branch->city);
            if (! $missing || ! $hasAddress) {
                return;
            }
            try {
                $query = implode(', ', array_filter([$branch->address, $branch->city, $branch->pincode, 'India']));
                $coords = app(\App\Services\Geocode\Geocoder::class)->geocode($query);
                if ($coords) {
                    // write without re-firing this hook
                    static::withoutEvents(fn () => $branch->newQuery()->whereKey($branch->getKey())
                        ->update(['latitude' => $coords['lat'], 'longitude' => $coords['lng']]));
                }
            } catch (\Throwable) {
                // ignore — the branches:geocode command can backfill later
            }
        });
    }
}

