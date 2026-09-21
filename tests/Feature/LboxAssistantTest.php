<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVisit;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use App\Models\SalesInvoice;
use App\Services\Lbox\AssistantService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The counter questions the box must answer from real rows (2026-09-21 list). */
class LboxAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['lbox.tts.enabled' => false]);   // answers only - no voice render in tests
        $this->branch = Branch::create(['name' => 'Rajapalayam', 'country' => 'IN', 'is_active' => true]);
        $this->device = Device::create(['name' => 'Counter Box', 'serial_no' => 'LBX-T-1', 'board_type' => 'lite', 'branch_id' => $this->branch->id]);
        // latestFor() memoises per process: the rate must exist before the first question
        LiveRate::create(['country' => 'IN', 'gold' => 7250, 'silver' => 95, 'diamond' => 0, 'effective_at' => now()]);
    }

    protected function ask(string $q): array
    {
        return app(AssistantService::class)->ask($this->device, $q);
    }

    public function test_sales_today_and_this_month_come_from_this_branch_invoices(): void
    {
        $other = Branch::create(['name' => 'Elsewhere', 'country' => 'IN', 'is_active' => true]);
        SalesInvoice::create(['invoice_no' => 'A1', 'date' => today(), 'branch_id' => $this->branch->id, 'net_total' => 12500]);
        SalesInvoice::create(['invoice_no' => 'A2', 'date' => today(), 'branch_id' => $this->branch->id, 'net_total' => 7500]);
        SalesInvoice::create(['invoice_no' => 'A3', 'date' => today()->subDays(3), 'branch_id' => $this->branch->id, 'net_total' => 1000]);
        SalesInvoice::create(['invoice_no' => 'B1', 'date' => today(), 'branch_id' => $other->id, 'net_total' => 99999]);

        $r = $this->ask('how much sales today');
        $this->assertSame('sales_today', $r['intent']);
        $this->assertStringContainsString('2 invoices', $r['answer']);
        $this->assertStringContainsString('20,000', $r['answer']);

        $r = $this->ask('how many sales this month');
        $this->assertSame('sales_month', $r['intent']);
        // the 3-day-old invoice counts only if this month started before it
        $expected = today()->subDays(3)->month === today()->month ? '3 invoices' : '2 invoices';
        $this->assertStringContainsString($expected, $r['answer']);
    }

    public function test_card_reads_today_count_taps_and_distinct_staff_on_this_box(): void
    {
        $rank = Rank::where('depth', 1)->firstOrFail();
        $profiles = [];
        foreach (['E1', 'E2'] as $code) {
            $m = Member::create(['member_code' => $code, 'name' => "Staff $code", 'phone' => '9'.random_int(100000000, 999999999),
                'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank->id]);
            MemberWallet::create(['member_id' => $m->id]);
            $profiles[] = EmployeeProfile::create(['member_id' => $m->id, 'employee_code' => "EMP-$code", 'date_of_joining' => now()->subYear(), 'status' => 'active']);
        }
        EmployeeVisit::create(['employee_profile_id' => $profiles[0]->id, 'device_id' => $this->device->id, 'branch_id' => $this->branch->id, 'visited_at' => now()]);
        EmployeeVisit::create(['employee_profile_id' => $profiles[0]->id, 'device_id' => $this->device->id, 'branch_id' => $this->branch->id, 'visited_at' => now()]);
        EmployeeVisit::create(['employee_profile_id' => $profiles[1]->id, 'device_id' => $this->device->id, 'branch_id' => $this->branch->id, 'visited_at' => now()]);
        EmployeeVisit::create(['employee_profile_id' => $profiles[1]->id, 'device_id' => $this->device->id, 'branch_id' => $this->branch->id, 'visited_at' => now()->subDay()]);

        $r = $this->ask('how many card read today');
        $this->assertSame('card_reads', $r['intent']);
        $this->assertStringContainsString('3 cards from 2 staff members', $r['answer']);

        $this->assertSame('card_reads', $this->ask('how many visitors are there')['intent']);
    }

    public function test_liveness_location_time_and_volume_intents(): void
    {
        $this->assertSame('ping', $this->ask('could you react')['intent']);
        $this->assertSame('ping', $this->ask('can you hear me')['intent']);
        $this->assertSame('branch', $this->ask('where are you now')['intent']);
        $this->assertStringContainsString('Rajapalayam', $this->ask('where are you now')['answer']);
        $this->assertSame('datetime', $this->ask('what is time now')['intent']);
        $this->assertSame('volume_up', $this->ask('volume up')['action']);
        $this->assertSame('volume_down', $this->ask('volume down')['action']);
        // "today" alone must not hijack a sales question into the clock
        $this->assertSame('sales_today', $this->ask('what is branch sales')['intent']);
    }

    public function test_tamil_box_hearing_an_english_question_gets_a_second_pass_in_english(): void
    {
        $this->device->update(['language' => 'ta', 'status' => 'active']);   // the voice route refuses a box that is not active
        \Laravel\Sanctum\Sanctum::actingAs($this->device, ['*']);
        $this->mock(\App\Services\Lbox\SttService::class, function ($m) {
            // first pass with the Tamil hint hears English as Tamil letters; second pass in English is clear
            $m->shouldReceive('transcribe')->withArgs(fn ($p, $h) => $h === 'ta')->once()->andReturn('வாட் இஸ் த டைம் நவ்');
            $m->shouldReceive('transcribe')->withArgs(fn ($p, $h) => $h === 'en')->once()->andReturn('what is the gold rate');
        });

        $wav = \Illuminate\Http\UploadedFile::fake()->createWithContent('q.wav', str_repeat("\0", 4000));
        $r = $this->postJson('/api/device/v1/ai/voice', ['audio' => $wav]);
        $r->assertOk()->assertJsonPath('intent', 'gold_rate')->assertJsonPath('transcript', 'what is the gold rate');
    }

    public function test_transliterated_tamil_keywords_still_match(): void
    {
        $this->device->update(['language' => 'ta']);
        $this->assertSame('gold_rate', $this->ask('கோல்ட் ரேட் என்ன')['intent']);
        $this->assertSame('datetime', $this->ask('டைம் என்ன')['intent']);
    }
}
