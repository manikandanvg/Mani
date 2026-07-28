<?php

namespace Tests\Feature\Api;

use App\Models\Bond;
use App\Models\Customer;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedemptionInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2b — document vault: a member's contracts + tax invoices with signed download URLs.
 */
class MemberDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create(['member_code' => 'DV1', 'name' => 'Doc', 'phone' => '9000000001', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active']);
    }

    protected function bond(): Bond
    {
        $plan = Plan::create(['code' => 'P206', 'name' => ['en' => 'G10 Gold'], 'plan_type' => 1, 'type' => 'gold', 'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true]);

        return Bond::create(['member_id' => $this->member->id, 'plan_id' => $plan->id, 'bond_date' => now(), 'value' => 50000, 'status' => 'active', 'invoice_no' => 'INV-DV1']);
    }

    public function test_documents_require_member_token(): void
    {
        Sanctum::actingAs(Customer::create(['phone' => '9111111111']), ['*']);
        $this->getJson('/api/v1/member/documents')->assertStatus(403);
    }

    public function test_lists_contracts_and_invoices_with_signed_urls(): void
    {
        Sanctum::actingAs($this->member, ['*']);
        $bond = $this->bond();
        $branch = \App\Models\Branch::create(['name' => 'B', 'country' => 'IN', 'is_active' => true]);
        RedemptionInvoice::create(['invoice_no' => 'RDM-1', 'invoice_date' => now(), 'member_id' => $this->member->id, 'branch_id' => $branch->id, 'taxable_total' => 100, 'cgst' => 0, 'sgst' => 0, 'grand_total' => 100, 'created_by' => null]);

        $res = $this->getJson('/api/v1/member/documents')->assertOk()
            ->assertJsonStructure(['data' => [['type', 'id', 'title', 'reference', 'date', 'download_url']]]);

        $types = collect($res->json('data'))->pluck('type');
        $this->assertContains('contract', $types);
        $this->assertContains('redemption_invoice', $types);
        $this->assertStringContainsString('signature=', collect($res->json('data'))->firstWhere('type', 'contract')['download_url']);
    }

    public function test_download_rejects_unsigned_request(): void
    {
        $bond = $this->bond();
        $this->get("/api/v1/member/documents/contract/{$bond->id}?m={$this->member->id}")->assertStatus(403);
    }

    public function test_signed_url_for_another_members_document_is_not_found(): void
    {
        // A validly-signed URL, but the bond belongs to someone else → ownership check 404s.
        $other = Member::create(['member_code' => 'OT', 'name' => 'Other', 'phone' => '9000000009', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active']);
        $otherBond = Bond::create(['member_id' => $other->id, 'plan_id' => $this->bond()->plan_id, 'bond_date' => now(), 'value' => 1, 'status' => 'active', 'invoice_no' => 'X']);

        $url = URL::temporarySignedRoute('api.member.document', now()->addMinutes(15), [
            'type' => 'contract', 'id' => $otherBond->id, 'm' => $this->member->id,
        ]);

        $this->get($url)->assertNotFound();
    }
}
