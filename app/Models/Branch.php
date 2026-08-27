<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
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

    public function members() { return $this->hasMany(Member::class); }

    public function users() { return $this->hasMany(User::class); }

    /** The branch one hop up the chain that this branch orders stock from. */
    public function sourceBranch() { return $this->belongsTo(Branch::class, 'source_branch_id'); }

    /** Branches that source their stock from this branch (one hop down). */
    public function childBranches() { return $this->hasMany(Branch::class, 'source_branch_id'); }

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

