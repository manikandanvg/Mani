<?php

namespace Database\Seeders;

use App\Models\Bond;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Services\CommissionService;
use App\Services\NetworkService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Small demo network so the admin panel has data and the engine has something to
 * crunch. Safe to run repeatedly (uses fixed codes).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class); // ranks/currencies/languages/admin

        $member = Rank::where('depth', 0)->first();

        $plan = Plan::firstOrCreate(['code' => 'G11-PLUS3'], [
            'name' => ['en' => 'G11 Gold Savings Plus3'],
            'type' => 'rd',
            'min_value' => 1000,
            'allocation_pct' => 100,
            'validity_months' => 11,
            'cbc_value' => 0,
            'cbc_count' => 0,
            'ic_schedule' => ['10', '3', '2', '1.5', '0.75'],
            'level_schedule' => ['2.5', '1', '0.5', '0.5', '0.5'],
            'epin_count' => 1,
            'is_active' => true,
        ]);

        // 7-member upline chain/tree
        $root = $this->member('LJW0001', 'Root Director', null, $member->id);
        $a = $this->member('LJW0002', 'Member A', $root->id, $member->id);
        $b = $this->member('LJW0003', 'Member B', $a->id, $member->id);
        $c = $this->member('LJW0004', 'Member C', $b->id, $member->id);
        $d = $this->member('LJW0005', 'Member D', $c->id, $member->id);
        $this->member('LJW0006', 'Member E', $a->id, $member->id);
        $this->member('LJW0007', 'Member F', $b->id, $member->id);

        foreach (Member::all() as $m) {
            Bond::firstOrCreate(
                ['member_id' => $m->id, 'invoice_no' => 'DEMO-' . $m->member_code],
                [
                    'plan_id' => $plan->id,
                    'bond_date' => Carbon::now()->subMonths(2),
                    'value' => 100000,
                    'lvlcom_count' => 11,
                    'lvlcom_issued' => 0,
                    'cbc_value' => 5000,
                    'cbc_count' => 11,
                    'cbc_issued' => 0,
                    'epin_value' => 100000,
                    'status' => 'active',
                ]
            );
        }

        app(NetworkService::class)->recomputeAll();
        foreach (Bond::with('plan', 'member')->get() as $bond) {
            app(CommissionService::class)->issueInstantCommission($bond);
        }
    }

    protected function member(string $code, string $name, ?int $uplineId, int $rankId): Member
    {
        $m = Member::firstOrCreate(
            ['member_code' => $code],
            [
                'name' => $name,
                'phone' => '90000' . substr($code, -5),
                'joined_on' => Carbon::now()->subMonths(3),
                'upline_id' => $uplineId,
                'placement' => 'level',
                'rank_id' => $rankId,
                'status' => 'active',
            ]
        );
        MemberWallet::firstOrCreate(['member_id' => $m->id]);

        return $m;
    }
}
