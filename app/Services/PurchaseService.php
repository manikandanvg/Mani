<?php

namespace App\Services;

use App\Models\Bond;
use App\Models\Epin;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POS / front-desk sale flow: turns a plan purchase into a bond and immediately
 * pays instant commission, all in one transaction. Optionally enrols a brand-new
 * member as part of the same sale. Network aggregates are recomputed at the end so
 * BV/GBV/rank reflect the new business right away.
 */
class PurchaseService
{
    public function __construct(
        private CommissionService $commissions,
        private NetworkService $network,
    ) {}

    /**
     * @param  array  $data  keys:
     *   member_id|new_member, plan_id, value, bond_date?, branch_id?, product_id?,
     *   invoice_no?, issue_ic? (bool, default true), generate_epins? (bool)
     */
    public function registerPurchase(array $data): Bond
    {
        return DB::transaction(function () use ($data) {
            $plan = Plan::findOrFail($data['plan_id']);
            $member = $this->resolveMember($data);

            MemberWallet::firstOrCreate(['member_id' => $member->id]);

            $value = (float) $data['value'];
            $bondDate = $data['bond_date'] ?? Carbon::now()->toDateString();

            $bond = Bond::create([
                'member_id' => $member->id,
                'plan_id' => $plan->id,
                'branch_id' => $data['branch_id'] ?? $member->branch_id,
                'product_id' => $data['product_id'] ?? null,
                'bond_date' => $bondDate,
                'value' => $value,
                'invoice_no' => $data['invoice_no'] ?? $this->nextInvoiceNo(),
                'cbc_value' => (float) ($plan->cbc_value ?? 0),
                'cbc_count' => (int) ($plan->cbc_count ?? 0),
                'cbc_issued' => 0,
                'lvlcom_count' => (int) $plan->validity_months,
                'lvlcom_issued' => 0,
                'epin_value' => 0,
                'mou_id' => $plan->mou_id,
                'status' => 'active',
            ]);

            if (! empty($data['generate_epins']) && (int) $plan->epin_count > 0) {
                $this->generateEpins($member, $bond, (int) $plan->epin_count, $value);
            }

            if ($data['issue_ic'] ?? true) {
                $this->commissions->issueInstantCommission($bond);
            }

            // keep BV / GBV / ranks consistent with the new bond
            $this->network->recomputeAll();

            return $bond;
        });
    }

    /** Use an existing member or create one inline from new_member attributes. */
    protected function resolveMember(array $data): Member
    {
        if (! empty($data['member_id'])) {
            return Member::findOrFail($data['member_id']);
        }

        $attrs = $data['new_member'] ?? [];
        $attrs['member_code'] ??= $this->nextMemberCode();
        $attrs['joined_on'] ??= Carbon::now()->toDateString();
        $attrs['status'] ??= 'active';

        return Member::create($attrs);
    }

    /** Issue plan.epin_count e-pins to the buyer, each worth an equal share of value. */
    protected function generateEpins(Member $member, Bond $bond, int $count, float $value): void
    {
        $worth = round($value / max(1, $count), 2);
        for ($i = 0; $i < $count; $i++) {
            Epin::create([
                'code' => 'EP' . strtoupper(Str::random(10)),
                'owner_member_id' => $member->id,
                'worth' => $worth,
                'status' => 'available',
            ]);
        }
        $bond->update(['epin_value' => round($worth * $count, 2)]);
    }

    protected function nextInvoiceNo(): string
    {
        $n = (int) Bond::max('id') + 1;

        return 'BND-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    protected function nextMemberCode(): string
    {
        $n = (int) Member::max('id') + 1;

        return 'LJW' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
