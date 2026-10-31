<?php

namespace App\Services\Geo;

use App\Models\Branch;
use App\Models\Member;
use App\Models\Pincode;
use Illuminate\Support\Collection;

/**
 * Board 2026-10-31: automatic mapping of the dealer hierarchy and of distributors to
 * branches BY PINCODE.
 *
 * Locality of two pincodes is scored from the pincode master:
 *   same pincode 40 · same taluka 30 · same district 20 · same state 10 · else 0,
 * plus a small bonus for the closest allowed level above (a reseller prefers its taluka
 * dealer over the zonal one) and, when both sides have map pins, a distance tie-break.
 *
 *   mapBranch($dealer)  → the best supplier among the levels its level may buy from
 *                         (Branch::ALLOWED_SOURCES); Head Office when nothing local exists.
 *   mapMember($member)  → the best selling branch (taluka / reseller / sub-dealer / wholesaler /
 *                         area distributor) for the distributor's pincode.
 */
class HierarchyMapper
{
    /** @var array<string, array{taluka:?string, district:?string, state:?string}> */
    protected array $geo = [];

    /** @var Collection<int, Branch>|null */
    protected ?Collection $branches = null;

    public const MEMBER_LEVELS = ['taluk', 'reseller', 'sub_dealer', 'wholesaler', 'area_dealer', 'district', 'zonal', 'regional'];

    /**
     * Minimum locality for a mapping: the SAME DISTRICT (20). "Same state" alone would pair a
     * Perambalur dealer with a Salem one just because both are in Tamil Nadu — in that case the
     * dealer stays on Head Office and a distributor stays unmapped.
     */
    public const MIN_LOCALITY = 20;

    /** @return array{branch:?Branch, score:int, reason:string} */
    public function mapBranch(Branch $dealer): array
    {
        $allowed = Branch::allowedSourceLevels($dealer->level);
        $hq = $this->branches()->firstWhere('level', 'hq') ?? Branch::find(\App\Services\SalesService::HQ_BRANCH_ID);

        // A dealer carrying Head Office's own pincode (the legacy default for many rows) has no
        // locality of its own: it buys from Head Office directly.
        if ($hq && filled($hq->pincode) && trim((string) $dealer->pincode) === trim((string) $hq->pincode)) {
            return ['branch' => $hq, 'score' => 40, 'reason' => 'Head Office pincode ' . $hq->pincode, 'locality' => 40];
        }

        $candidates = $this->branches()
            ->reject(fn (Branch $b) => $b->id === $dealer->id || ! in_array($b->level, $allowed, true) || $b->level === 'hq')
            ->reject(fn (Branch $b) => $this->isDescendant($b, $dealer));

        $best = $this->best($dealer->pincode, $dealer, $candidates, fn (Branch $b) => $this->levelBonus($dealer->level, $b->level));

        // Stability: a supplier HQ already set that is at least as local as the proposal stays.
        $current = $dealer->source_branch_id ? $candidates->firstWhere('id', $dealer->source_branch_id) : null;
        if ($current) {
            [$curLocality, $curWhy] = $this->locality(trim((string) $dealer->pincode), $dealer->pincode ? $this->geoOf(trim((string) $dealer->pincode)) : null, $current);
            if ($curLocality >= self::MIN_LOCALITY && (! $best['branch'] || $curLocality >= $best['locality'])) {
                return ['branch' => $current, 'score' => $curLocality, 'reason' => 'kept current supplier (' . $curWhy . ')'];
            }
        }
        if ($best['branch']) {
            return $best;
        }

        return ['branch' => $hq, 'score' => 0, 'reason' => $hq ? 'no supplier in the same district — Head Office' : 'no supplier found'];
    }

    /** @return array{branch:?Branch, score:int, reason:string} */
    public function mapMember(Member $member): array
    {
        if (blank($member->pincode)) {
            return ['branch' => null, 'score' => 0, 'reason' => 'distributor has no pincode'];
        }
        $candidates = $this->branches()->filter(fn (Branch $b) => in_array($b->level, self::MEMBER_LEVELS, true));
        $best = $this->best($member->pincode, null, $candidates, fn (Branch $b) => 8 - min(8, Branch::levelIndex($b->level)));   // closer-to-counter levels first
        if ($best['branch']) {
            return $best;
        }

        return ['branch' => null, 'score' => 0, 'reason' => 'no branch in the same district'];
    }

    /** Whole-network proposals for branches: [{branch, current, proposed, reason, score}]. */
    public function proposeBranches(bool $onlyUnmapped = false): array
    {
        $out = [];
        foreach ($this->branches()->where('level', '!=', 'hq')->sortBy('name') as $b) {
            if ($onlyUnmapped && $b->source_branch_id) {
                continue;
            }
            $r = $this->mapBranch($b);
            $out[] = ['branch' => $b, 'current' => $b->sourceBranch, 'proposed' => $r['branch'], 'reason' => $r['reason'], 'score' => $r['score'],
                'changes' => $r['branch'] && (int) $r['branch']->id !== (int) $b->source_branch_id];
        }

        return $out;
    }

    // ------------------------------------------------------------------ scoring

    /** @param  Collection<int, Branch>  $candidates */
    protected function best(?string $pincode, ?Branch $self, Collection $candidates, callable $bonus): array
    {
        $pin = trim((string) $pincode);
        $g = $pin !== '' ? $this->geoOf($pin) : null;
        $bestBranch = null;
        $bestScore = -1;
        $bestWhy = '';
        $bestLocality = 0;
        foreach ($candidates as $c) {
            [$locality, $why] = $this->locality($pin, $g, $c);
            if ($locality < self::MIN_LOCALITY) {
                continue;
            }
            $score = $locality + $bonus($c);
            // tie-break by distance when both have map pins (0–5 points, nearer = more)
            if ($self && $self->latitude && $c->latitude) {
                $km = $this->km((float) $self->latitude, (float) $self->longitude, (float) $c->latitude, (float) $c->longitude);
                $score += max(0, 5 - (int) floor($km / 20));
            }
            if ($score > $bestScore) {
                [$bestBranch, $bestScore, $bestWhy, $bestLocality] = [$c, $score, $why, $locality];
            }
        }

        return ['branch' => $bestBranch, 'score' => max(0, $bestScore), 'reason' => $bestWhy ?: 'no match', 'locality' => $bestLocality];
    }

    /** @return array{0:int,1:string} */
    protected function locality(string $pin, ?array $g, Branch $c): array
    {
        $cp = trim((string) $c->pincode);
        if ($pin === '' || $cp === '') {
            return [0, ''];
        }
        if ($cp === $pin) {
            return [40, 'same pincode ' . $pin];
        }
        if (! $g) {
            return [0, ''];
        }
        $cg = $this->geoOf($cp);
        if (! $cg) {
            return [0, ''];
        }
        if ($g['taluka'] && $g['taluka'] === $cg['taluka']) {
            return [30, 'same taluka ' . $g['taluka']];
        }
        if ($g['district'] && $g['district'] === $cg['district']) {
            return [20, 'same district ' . $g['district']];
        }
        if ($g['state'] && $g['state'] === $cg['state']) {
            return [10, 'same state ' . $g['state']];
        }

        return [0, ''];
    }

    /** Prefer the nearest allowed level above the dealer (taluka for a reseller, district for a taluka …). */
    protected function levelBonus(?string $own, ?string $supplier): int
    {
        $d = Branch::levelIndex($own) - Branch::levelIndex($supplier);

        return $d > 0 ? max(0, 6 - $d) : 2;
    }

    protected function isDescendant(Branch $candidate, Branch $of): bool
    {
        $seen = [];
        $b = $candidate;
        while ($b && $b->source_branch_id && ! isset($seen[$b->id])) {
            if ((int) $b->source_branch_id === (int) $of->id) {
                return true;
            }
            $seen[$b->id] = true;
            $b = $this->branches()->firstWhere('id', $b->source_branch_id);
        }

        return false;
    }

    /** @return array{taluka:?string, district:?string, state:?string}|null */
    public function geoOf(string $pin): ?array
    {
        if (array_key_exists($pin, $this->geo)) {
            return $this->geo[$pin];
        }
        $rows = Pincode::where('pincode', $pin)->get(['taluka', 'district', 'state']);
        if ($rows->isEmpty()) {
            $api = app(PincodeService::class)->lookup($pin);   // caches the offices into the master
            $this->geo[$pin] = $api ? ['taluka' => $api['talukas'][0] ?? null, 'district' => $api['district'], 'state' => $api['state']] : null;
        } else {
            $this->geo[$pin] = [
                'taluka' => $rows->pluck('taluka')->filter()->mode()[0] ?? null,
                'district' => $rows->pluck('district')->filter()->first(),
                'state' => $rows->pluck('state')->filter()->first(),
            ];
        }
        foreach (['taluka', 'district', 'state'] as $k) {
            if ($this->geo[$pin] && $this->geo[$pin][$k]) {
                $this->geo[$pin][$k] = mb_strtoupper(trim($this->geo[$pin][$k]));
            }
        }

        return $this->geo[$pin];
    }

    protected function branches(): Collection
    {
        return $this->branches ??= Branch::where('is_active', true)->get();
    }

    protected function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
    }
}
