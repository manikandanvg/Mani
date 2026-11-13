<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'master';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'ic_schedule' => 'array',
        'level_schedule' => 'array',
        'min_value' => 'decimal:2',
        'max_value' => 'decimal:2',
        'max_sales_value' => 'decimal:2',
        'allocation_bv' => 'decimal:3',
        'allocation_cont' => 'decimal:3',
        'allocation' => 'decimal:3',
        'cbc_dealer_rule' => 'boolean',
        'cbc_value' => 'decimal:3',
        'renewal_margin' => 'decimal:3',
        'is_redeem' => 'boolean',
        'is_contract' => 'boolean',
        'is_invoice' => 'boolean',
        'is_active' => 'boolean',
        'useraccess' => 'boolean',
        'settlement_qr_pct' => 'decimal:2',
        'settlement_close' => 'boolean',
        'settlement_suspend' => 'boolean',
        'counts_for_rank' => 'boolean',
        'rank_factor' => 'decimal:2',
    ];

    /** Scheme availability by dealership kind (board 2026-10-24). */
    public const AVAILABLE_ALL = 'all';
    public const AVAILABLE_BRANCH = 'branch';
    public const AVAILABLE_DEALER = 'dealer';
    /** Head Office (HQ / admin) only — no dealer and no other Lord's Branch may bill it (user 2026-11-13, G12). */
    public const AVAILABLE_HQ = 'hq';
    public const AVAILABILITY_LABELS = [
        self::AVAILABLE_ALL => "Both — Lord's Branches and dealers",
        self::AVAILABLE_BRANCH => "Lord's Branches only",
        self::AVAILABLE_DEALER => 'Dealers only',
        self::AVAILABLE_HQ => 'Head Office (HQ) only',
    ];

    public static function availabilityLabel(?string $v): string
    {
        return self::AVAILABILITY_LABELS[$v ?: self::AVAILABLE_ALL] ?? ucfirst((string) $v);
    }

    /** May this scheme be billed at the given branch (by its kind)? HQ counts as a Lord's Branch. */
    public function availableAt(?Branch $branch): bool
    {
        $mode = $this->available_to ?: self::AVAILABLE_ALL;
        if ($mode === self::AVAILABLE_ALL || ! $branch) {
            return true;
        }

        if ($mode === self::AVAILABLE_HQ) {
            return $branch->level === 'hq';
        }

        return $branch->kindForSchemes() === $mode;
    }

    /** Query scope: schemes billable at a branch of this kind ('branch' | 'dealer'). */
    public function scopeAvailableFor($query, ?string $kind, bool $isHq = false)
    {
        if (! $kind) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('available_to')
            ->orWhere('available_to', self::AVAILABLE_ALL)
            ->orWhere('available_to', $kind)
            ->when($isHq, fn ($w) => $w->orWhere('available_to', self::AVAILABLE_HQ)));
    }

    public function mou() { return $this->belongsTo(Mou::class); }

    public function rdQrProduct() { return $this->belongsTo(CatalogProduct::class, 'rd_qr_product_id'); }

    /**
     * Ranking-BV multiplier: rank_factor is the explicit 0-100 percentage of the
     * ORIGINAL billed value that counts as pure/ranking BV (schema v2); excluded
     * plans contribute 0.
     */
    public function rankFactor(): float
    {
        if (! $this->counts_for_rank) {
            return 0.0;
        }

        return (float) $this->rank_factor / 100;
    }

    /**
     * Legacy allocation (user 2026-11-04, "bill the legacy way"): the % of a bill that becomes
     * the bond — legacy tbl_bond.bvalue = bill × allocation (80 % on a dealership, 50 % G5,
     * 25 % Sub Dealer, 10 % Area, 100 % on the saving / purchase plans). Every commission is
     * computed on the FULL bill, not on this share. Falls back to the contract share, then 100.
     */
    public function bondShare(): float
    {
        if ($this->allocation !== null && (float) $this->allocation > 0) {
            return (float) $this->allocation;
        }

        return (float) $this->allocation_cont > 0 ? (float) $this->allocation_cont : 100.0;
    }

    /** Bond value (legacy bvalue) for a bill of $billValue. */
    public function bondValueFor(float $billValue): float
    {
        return round($billValue * $this->bondShare() / 100, 2);
    }

    /**
     * Ranking (unpure) BV contributed by a bond. rank_factor % applies to the ORIGINAL
     * billed value, but bonds store the legacy allocation share — so scale back up first.
     * G5 example: ₹1,00,000 bill × 50 % → bond 50,000; unpure = 1,00,000 × 50 % = 50,000
     * (NOT 50 % of 50,000).
     */
    public function unpureFromBondValue(float $bondValue, ?float $epinValue = null): float
    {
        $factor = $this->rankFactor();
        if ($factor <= 0 || ($bondValue <= 0 && (float) $epinValue <= 0)) {
            return 0.0;
        }

        return round($this->billValueFromBond($bondValue, $epinValue) * $factor, 2);
    }

    /**
     * The bill a bond came from. bonds.epin_value holds it as billed (legacy tbl_bond.epinvalue,
     * the grand total) — legacy mybv/mygbv and the R/A BV cron sum exactly that, so prefer it;
     * derive from the bond share (bond ÷ allocation) only when it was never stored. Deriving
     * under-counts bonds booked under an older share (old G24 bonds carry a 30 % bvalue).
     */
    public function billValueFromBond(float $bondValue, ?float $epinValue = null): float
    {
        if ((float) $epinValue > 0) {
            return round((float) $epinValue, 2);
        }
        $alloc = $this->bondShare();

        return round(($alloc > 0 && $alloc < 100) ? $bondValue * 100 / $alloc : $bondValue, 2);
    }
}
