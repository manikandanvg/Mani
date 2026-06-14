<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Limits a resource's records to the logged-in distributor's own branch. Head-office
 * staff (super_admin / admin / other non-distributor roles) see every branch. This is
 * the new-app equivalent of the legacy "session branchid" filter that scoped a
 * semi-admin distributor to their store only.
 *
 * The scoped column defaults to `branch_id`; override branchColumn() where it differs
 * (e.g. digi_queue uses `redeem_branch_id`).
 */
trait BranchScoped
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && method_exists($user, 'isDistributor') && $user->isDistributor() && $user->branch_id) {
            $query->where(static::branchColumn(), $user->branch_id);
        }

        return $query;
    }

    protected static function branchColumn(): string
    {
        return 'branch_id';
    }
}
