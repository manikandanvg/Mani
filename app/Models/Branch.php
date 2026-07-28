<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /** Supply-chain levels, top → bottom. 'area_dealer' is a free-sourcing leaf. */
    public const LEVELS = ['hq', 'zonal', 'district', 'taluk', 'wholesaler', 'reseller', 'area_dealer'];

    /** Seller levels that earn a stock-transfer margin (HQ earns none; area dealers don't sell on). */
    public const SELLER_LEVELS = ['zonal', 'district', 'taluk', 'wholesaler', 'reseller'];

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
     * Branches that may act as THIS branch's supplier (order source): any active branch at a
     * level strictly ABOVE this one in the chain — i.e. Head Office + the upper seller levels
     * (zonal…reseller), never a peer/lower level. Used to constrain the source dropdown.
     */
    public function sourceCandidates()
    {
        $own = static::levelIndex($this->level);
        $valid = array_merge(['hq'], self::SELLER_LEVELS);                 // hq + zonal…reseller
        $above = array_values(array_filter(
            array_slice(self::LEVELS, 0, $own),
            fn ($l) => in_array($l, $valid, true),
        ));

        return static::query()
            ->whereKeyNot($this->getKey())
            ->whereIn('level', $above)
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

