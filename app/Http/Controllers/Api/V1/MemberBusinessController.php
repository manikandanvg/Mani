<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bond;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\ResellerCommission;
use App\Support\Translatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My Business" — the distributor's MLM area (Phase 2): dashboard, downline,
 * earnings (IC / GAP / CBC / reseller margins), and bonds/RD. Member-only — a
 * retail customer token gets 403.
 */
class MemberBusinessController extends Controller
{
    /** GET /member/dashboard — headline numbers for the home dashboard. */
    public function dashboard(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $member->load(['rank', 'wallet']);

        $bonds = Bond::where('member_id', $member->id);
        $activeBonds = (clone $bonds)->where('status', 'active')->count();
        $invested = (clone $bonds)->sum('value');

        return response()->json([
            'member' => [
                'id' => $member->id,
                'member_code' => $member->member_code,
                'name' => $member->name,
                'rank' => Translatable::pick($member->rank?->name),
                'joined_on' => optional($member->joined_on)->toDateString(),
                'status' => $member->status,
            ],
            'wallet' => $this->wallet($member),
            'network' => [
                'bv' => (float) $member->bv,
                'gbv' => (float) $member->gbv,
                'direct_downlines' => Member::where('upline_id', $member->id)->count(),
                'team_size' => (int) $member->downline_count,
            ],
            'earnings' => $this->earningsSummary($member),
            'bonds' => [
                'active' => $activeBonds,
                'total' => (clone $bonds)->count(),
                'invested' => round((float) $invested, 2),
            ],
        ]);
    }

    /** GET /member/downline — direct downlines + counts. */
    public function downline(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $direct = Member::where('upline_id', $member->id)
            ->with('rank')
            ->orderByDesc('joined_on')
            ->get()
            ->map(fn (Member $m) => [
                'id' => $m->id,
                'member_code' => $m->member_code,
                'name' => $m->name,
                'joined_on' => optional($m->joined_on)->toDateString(),
                'rank' => Translatable::pick($m->rank?->name),
                'status' => $m->status,
                'bv' => (float) $m->bv,
                'gbv' => (float) $m->gbv,
                'team_size' => (int) $m->downline_count,
            ]);

        return response()->json([
            'counts' => [
                'direct' => $direct->count(),
                'active' => $direct->where('status', 'active')->count(),
                'team_size' => (int) $member->downline_count,
            ],
            'direct' => $direct->values(),
        ]);
    }

    /** GET /member/earnings/summary — totals per stream. */
    public function earningsSummaryEndpoint(Request $request): JsonResponse
    {
        return response()->json($this->earningsSummary($this->member($request)));
    }

    /** GET /member/earnings?stream=IC|GAP|CBC|RESELLER — paginated ledger. */
    public function earnings(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $stream = strtoupper((string) $request->query('stream', 'IC'));

        $rows = match ($stream) {
            'CBC' => $this->cbcQuery($member)->latest('cbc_date')->paginate(20)
                ->through(fn (CbcEntry $r) => [
                    'stream' => 'CBC',
                    'date' => optional($r->cbc_date)->toDateString(),
                    'amount' => (float) $r->worth,
                    'status' => $r->status,
                    'reference' => $r->code,
                    'paid_on' => optional($r->paid_on)->toDateString(),
                ]),
            'RESELLER' => $this->resellerQuery($member)->latest('bill_date')->paginate(20)
                ->through(fn (ResellerCommission $r) => [
                    'stream' => $this->resellerLabel($r->com_type_id),
                    'date' => optional($r->bill_date)->toDateString(),
                    'amount' => (float) $r->com_value,
                    'status' => $r->status,
                    'reference' => $r->invoice_no,
                    'net' => (float) $r->net_amount,
                    'paid_on' => optional($r->paid_on)->toDateString(),
                ]),
            default => $this->ledgerQuery($member, $stream)->latest('earned_on')->with('fromMember')->paginate(20)
                ->through(fn (CommissionLedger $r) => [
                    'stream' => $r->type,
                    'date' => optional($r->earned_on)->toDateString(),
                    'amount' => (float) $r->amount,
                    'status' => $r->status,
                    'from' => $r->fromMember?->name,
                    'reference' => $r->invoice_no,
                    'net' => (float) $r->net_amount,
                    'paid_on' => optional($r->paid_on)->toDateString(),
                ]),
        };

        return response()->json($rows);
    }

    /** GET /member/bonds — the member's bonds/plans with progress. */
    public function bonds(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $bonds = Bond::where('member_id', $member->id)
            ->with('plan')
            ->withCount('rdEntries')
            ->latest('bond_date')
            ->get()
            ->map(fn (Bond $b) => [
                'id' => $b->id,
                'invoice_no' => $b->invoice_no,
                'plan' => [
                    'code' => $b->plan?->code,
                    'name' => Translatable::pick($b->plan?->name) ?: $b->plan?->code,
                    'type' => $b->plan?->type,
                ],
                'value' => (float) $b->value,
                'bond_date' => optional($b->bond_date)->toDateString(),
                'expires_on' => optional($b->expires_on)->toDateString(),
                'status' => $b->status,
                'cbc' => ['issued' => (int) $b->cbc_issued, 'count' => (int) $b->cbc_count],
                'lvlcom' => ['issued' => (int) $b->lvlcom_issued, 'count' => (int) $b->lvlcom_count],
                'rd_paid' => (int) $b->rd_entries_count,
            ]);

        return response()->json(['data' => $bonds]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** Resolve the authenticated member, or 403 for a non-member (customer) token. */
    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        return $user;
    }

    protected function wallet(Member $member): array
    {
        $w = $member->wallet;

        return [
            'currency_code' => $w?->currency_code ?: 'INR',
            'cash_balance' => (float) ($w?->cash_balance ?? 0),
            'epin_balance' => (float) ($w?->epin_balance ?? 0),
            'coupon_balance' => (float) ($w?->coupon_balance ?? 0),
            'earning_total' => (float) ($w?->earning_total ?? 0),
        ];
    }

    protected function earningsSummary(Member $member): array
    {
        $byType = fn (string $type) => [
            'total' => round((float) $this->ledgerQuery($member, $type)->sum('amount'), 2),
            'paid' => round((float) (clone $this->ledgerQuery($member, $type))->where('status', 'paid')->sum('amount'), 2),
            'pending' => round((float) (clone $this->ledgerQuery($member, $type))->where('status', '!=', 'paid')->sum('amount'), 2),
        ];

        return [
            'ic' => $byType('IC'),
            'gap' => $byType('GAP'),
            'cbc' => [
                'total' => round((float) $this->cbcQuery($member)->sum('worth'), 2),
                'paid' => round((float) (clone $this->cbcQuery($member))->where('status', 'paid')->sum('worth'), 2),
                'pending' => round((float) (clone $this->cbcQuery($member))->where('status', '!=', 'paid')->sum('worth'), 2),
            ],
            'reseller' => [
                'total' => round((float) $this->resellerQuery($member)->sum('com_value'), 2),
                'paid' => round((float) (clone $this->resellerQuery($member))->where('status', 'paid')->sum('com_value'), 2),
            ],
        ];
    }

    protected function ledgerQuery(Member $member, string $type)
    {
        return CommissionLedger::where('member_id', $member->id)->where('type', $type);
    }

    protected function cbcQuery(Member $member)
    {
        return CbcEntry::where('member_id', $member->id);
    }

    protected function resellerQuery(Member $member)
    {
        return ResellerCommission::where('reference_member_id', $member->id);
    }

    protected function resellerLabel(int $comTypeId): string
    {
        return [1 => 'BILL_MARGIN', 2 => 'GOLD_MARGIN', 3 => 'SILVER_MARGIN', 4 => 'STOCK_TRANSFER_MARGIN', 5 => 'RD_RENEWAL_MARGIN'][$comTypeId] ?? 'MARGIN';
    }
}
