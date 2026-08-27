<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;

/**
 * Dealership ladder (board 2026-08-26). A dealership plan's `hid` decides the branch
 * level a dealer operates at; buying a higher plan upgrades the branch, never downgrades
 * it (HQ keeps the manual override on the Branch form). Also audits existing branches
 * against the per-level "buys from" allow-list so HQ can re-point broken pairs.
 */
class DealershipService
{
    /** Branch level a plan confers, by its hid — null for non-dealership plans. */
    public function levelForPlan(?Plan $plan): ?string
    {
        return $plan ? Branch::levelForHid($plan->hid) : null;
    }

    /**
     * After a plan is billed: if the member already runs a branch (dealer login mapped by
     * member_code, or members.branch_id) and the plan sits HIGHER on the ladder than the
     * branch's current level, promote the branch. Returns the new level or null.
     */
    public function applyPlanLevel(Member $member, ?Plan $plan): ?string
    {
        $level = $this->levelForPlan($plan);
        if (! $level) {
            return null;
        }
        $branch = $this->branchOf($member);
        if (! $branch || $branch->level === 'hq') {
            return null;
        }
        if ($branch->level !== null && Branch::levelIndex($level) >= Branch::levelIndex($branch->level)) {
            return null;   // same or lower plan — never auto-downgrade
        }
        $branch->update(['level' => $level]);

        return $level;
    }

    /** The branch a member operates (their dealer login's branch, else members.branch_id). */
    public function branchOf(Member $member): ?Branch
    {
        $userBranch = User::where('member_code', $member->member_code)->whereNotNull('branch_id')->value('branch_id');
        $id = $userBranch ?: $member->branch_id;

        return $id ? Branch::find($id) : null;
    }

    /**
     * Branches whose current source is not in their level's allow-list (legacy pairs such
     * as reseller ← wholesaler, or a Retailer sourced from a District), plus branches or
     * sources with no level set at all. HQ re-points / levels these on the Branch form;
     * nothing is blocked automatically.
     *
     * @return array<int, array{branch:Branch, source:?Branch, allowed:array, reason:string}>
     */
    public function auditSources(): array
    {
        $out = [];
        Branch::with('sourceBranch')->whereNotNull('source_branch_id')->orderBy('name')->get()
            ->each(function (Branch $b) use (&$out) {
                $src = $b->sourceBranch;
                if (! $src) {
                    return;
                }
                $allowed = Branch::allowedSourceLevels($b->level);
                $reason = match (true) {
                    $b->level === null => 'branch has no dealership level',
                    $src->level === null => 'source branch has no dealership level',
                    ! in_array($src->level, $allowed, true) => 'source level not allowed for this level',
                    default => null,
                };
                if ($reason !== null) {
                    $out[] = ['branch' => $b, 'source' => $src, 'allowed' => $allowed, 'reason' => $reason];
                }
            });

        return $out;
    }
}
