<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One change of a branch's bank details (board 2026-11-25): what it was, what it became, who
 * changed it and when. Written by the Branch model itself, whatever screen saved the branch.
 */
class BranchBankChange extends Model
{
    protected $guarded = [];

    protected $casts = ['is_first' => 'boolean'];

    public function branch() { return $this->belongsTo(Branch::class); }

    /** "Bank · holder · A/c · IFSC" of one side of the change. */
    public function describe(string $side): string
    {
        $parts = array_filter([
            $this->{$side . '_bank_name'},
            $this->{$side . '_bank_holder'},
            $this->{$side . '_bank_acno'} ? 'A/c ' . $this->{$side . '_bank_acno'} : null,
            $this->{$side . '_bank_ifsc'} ? 'IFSC ' . $this->{$side . '_bank_ifsc'} : null,
        ]);

        return $parts ? implode(' · ', $parts) : '—';
    }
}
