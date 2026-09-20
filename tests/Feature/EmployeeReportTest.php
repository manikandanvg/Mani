<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\EmployeeReport;
use App\Models\User;
use App\Support\BusinessModel\LegacyLordSource;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Employee Report (board 2026-09-18): GAP + IC earnings on the Lord DB above the ESI / PF threshold. */
class EmployeeReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_open_it_and_dealers_cannot(): void
    {
        $this->assertTrue(EmployeeReport::canAccess());

        $dealer = User::factory()->create(['status' => 'active']);
        $dealer->assignRole('distributor');
        $this->actingAs($dealer);
        $this->assertFalse(EmployeeReport::canAccess());
    }

    public function test_lists_members_above_the_threshold_with_registration_details(): void
    {
        if (! LegacyLordSource::available()) {
            $this->markTestSkipped('Legacy lordicl database not reachable on this machine.');
        }

        $sections = collect(Livewire::test(EmployeeReport::class)
            ->fillForm(['gap_date' => '2026-09-01', 'from' => '2026-08-01', 'to' => '2026-08-31', 'threshold' => 15000])
            ->call('run')->assertHasNoFormErrors()
            ->get('sections'))->keyBy('heading');

        $summary = $sections['Employee Report — earnings above ₹15,000.00'];
        $this->assertSame('01 Sep 2026', $summary['kv']['GAP run date']);
        $this->assertGreaterThan(0, (int) $summary['kv']['Members above the threshold']);

        $list = $sections['Distributors for ESI + PF registration'];
        $this->assertSame(['#', 'Name', 'Member ID', 'Father / Husband', 'DOB', 'DOJ', 'Mobile', 'Email', 'Address', 'PAN', 'Aadhaar',
            'Bank', 'Account No', 'IFSC', 'Nominee', 'Level', 'GAP (₹)', 'IC (₹)', 'Earnings (₹)'], $list['columns']);
        $this->assertGreaterThan(0, count($list['rows']));

        // Sorted by earnings, every row strictly above the threshold, member id present.
        $first = $list['rows'][0];
        $this->assertStringStartsWith('LJW', $first[2]);
        $earnings = array_map(fn ($r) => (float) str_replace(',', '', $r[18]), $list['rows']);
        $this->assertGreaterThan(15000, min($earnings));
        $this->assertSame($earnings, array_reverse(collect($earnings)->sort()->values()->all()));

        Livewire::test(EmployeeReport::class)
            ->fillForm(['gap_date' => '2026-09-01', 'from' => '2026-08-01', 'to' => '2026-08-31', 'threshold' => 15000])
            ->call('exportCsv')
            ->assertFileDownloaded();
    }
}
