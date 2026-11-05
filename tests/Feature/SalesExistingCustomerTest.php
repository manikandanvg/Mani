<?php

namespace Tests\Feature;

use App\Filament\Pages\Sales;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Rank;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * User 2026-11-02 (complaint 2): a dealer login billing an EXISTING distributor sees the phone /
 * PAN / Aadhaar masked (board 2026-10-31 item 19) and the sponsor / placement IDs fixed — and the
 * bill must still save: the masked phone is not a "valid telephone number" and a legacy root
 * member has no referrer, neither may block the form.
 */
class SalesExistingCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_bills_an_existing_distributor_with_masked_details_and_fixed_sponsor_ids(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $branch = Branch::create(['name' => 'HARSHA JEWELLERS', 'country' => 'IN', 'level' => 'reseller', 'kind' => 'dealer', 'is_active' => true]);
        $dealer = User::create(['name' => 'Harsha', 'email' => 'harsha@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id]);
        $dealer->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $root = Member::create(['member_code' => 'LJW2412451685', 'name' => 'RAJKUMAR A', 'phone' => '9943046680', 'pan' => 'EEZPR0798F', 'aadhaar' => '123456789012',
            'email' => 'raj@example.com', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id')]);
        $this->assertNull($root->referrer_id, 'a legacy root member has no sponsor');

        $this->actingAs($dealer);
        $this->assertFalse(Sales::showsSensitive(), 'a dealer login sees masked identity fields');

        $t = Livewire::test(Sales::class)
            ->fillForm(['mode' => 'existing'])
            ->set('data.customer.member_id', $root->id);   // the live select's afterStateUpdated fills the customer

        $state = $t->instance()->form->getRawState();
        $this->assertSame('RAJKUMAR A', $state['customer']['name']);
        $this->assertMatchesRegularExpression('/x/i', (string) $state['customer']['phone'], 'phone is masked for a dealer');
        $this->assertStringNotContainsString('9943046680', (string) $state['customer']['phone']);
        $this->assertMatchesRegularExpression('/x/i', (string) $state['customer']['pan']);
        $this->assertMatchesRegularExpression('/x/i', (string) $state['customer']['aadhaar']);
        $this->assertNull($state['referrer_code']);
        $this->assertNull($state['upline_code']);

        // generating runs the form validation: the masked phone and the blank sponsor IDs must not block it
        $t->call('generate')
            ->assertHasNoFormErrors(['customer.phone', 'customer.email', 'referrer_code', 'upline_code']);

        // the sponsor / placement IDs are fixed text for an existing distributor
        $t->assertFormFieldExists('referrer_code', 'form', fn (\Filament\Forms\Components\TextInput $f) => $f->isReadOnly())
            ->assertFormFieldExists('upline_code', 'form', fn ($f) => $f instanceof \Filament\Forms\Components\Hidden)
            ->assertFormFieldExists('customer.phone', 'form', fn (\Filament\Forms\Components\TextInput $f) => $f->isReadOnly());

        // the member's real details were not overwritten by the masked values
        $this->assertSame('9943046680', $root->fresh()->phone);
        $this->assertSame('EEZPR0798F', $root->fresh()->pan);

        // a NEW distributor still needs the Referred ID and a proper phone
        Livewire::test(Sales::class)
            ->fillForm(['mode' => 'new', 'customer.name' => 'New One', 'customer.phone' => '98XXXXXX21', 'referrer_code' => null, 'upline_code' => null])
            ->call('generate')
            ->assertHasFormErrors(['customer.phone', 'referrer_code']);

        // user 2026-11-05: the Senior (placement) ID is hidden and always carries the Referred ID
        $t = Livewire::test(Sales::class)->fillForm(['mode' => 'new'])->set('data.referrer_code', 'ljw01 ');
        $this->assertSame('LJW01', $t->instance()->form->getRawState()['upline_code']);
        $t->set('data.referrer_code', '');
        $this->assertNull($t->instance()->form->getRawState()['upline_code']);
        $t->assertDontSeeHtml('Senior Distributor User ID');
    }
}
