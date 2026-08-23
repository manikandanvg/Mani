<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Member;
use App\Models\Rank;
use App\Services\Payroll\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payroll attendance API (2026-07 board mandate): selfie + GPS check-in/out for
 * employee-enrolled distributors; everyone else is 403.
 */
class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected Member $employee;

    protected Member $plainMember;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $base = Rank::create(['code' => 'MEMBER', 'name' => ['en' => 'Distributor'], 'depth' => 0, 'target_bv' => 0]);
        $stage = Rank::create([
            'code' => 'TALUK_DIRECTOR', 'name' => ['en' => 'Taluk Admin'], 'depth' => 1,
            'target_bv' => 50000, 'monthly_salary' => 15000, 'tds_pct' => 5,
        ]);

        $this->employee = Member::create([
            'member_code' => 'ATT1', 'name' => 'Worker', 'phone' => '9000000040',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $stage->id, 'status' => 'active',
        ]);
        app(EmployeeService::class)->enroll($this->employee);

        $this->plainMember = Member::create([
            'member_code' => 'ATT2', 'name' => 'NotStaff', 'phone' => '9000000041',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $base->id, 'status' => 'active',
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/member/attendance')->assertStatus(401);
    }

    public function test_non_employee_member_and_customer_are_403(): void
    {
        Sanctum::actingAs($this->plainMember, ['*']);
        $this->getJson('/api/v1/member/attendance')->assertStatus(403);

        $customer = Customer::create(['phone' => '9777777777', 'name' => 'Shopper']);
        Sanctum::actingAs($customer, ['*']);
        $this->postJson('/api/v1/member/attendance/check-in')->assertStatus(403);
    }

    public function test_check_in_with_selfie_and_gps_then_check_out(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $res = $this->post('/api/v1/member/attendance/check-in', [
            'selfie' => UploadedFile::fake()->image('face.jpg', 480, 640),
            'lat' => 11.0168445,
            'lng' => 76.9558321,
            'accuracy' => 12.5,
        ], ['Accept' => 'application/json']);

        $res->assertStatus(201)
            ->assertJsonPath('record.status', 'present')
            ->assertJsonPath('record.source', 'app');

        $this->assertNotNull($res->json('record.check_in_at'));

        // the selfie landed on disk
        $employeeId = $this->employee->fresh()->employeeProfile->id;
        Storage::disk('public')->assertExists("attendance/{$employeeId}/" . now()->toDateString() . '-in.jpg');
        // ...and is re-encoded as a real JPEG (board 2026-08-23: compressed on save)
        $info = getimagesizefromstring(Storage::disk('public')->get("attendance/{$employeeId}/" . now()->toDateString() . '-in.jpg'));
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);

        // duplicate check-in is rejected
        $this->post('/api/v1/member/attendance/check-in', [
            'selfie' => UploadedFile::fake()->image('face2.jpg'),
            'lat' => 11.0, 'lng' => 76.9,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // check-out closes the day
        $this->postJson('/api/v1/member/attendance/check-out', ['lat' => 11.01, 'lng' => 76.95])
            ->assertOk();

        // second check-out rejected
        $this->postJson('/api/v1/member/attendance/check-out')->assertStatus(422);
    }

    public function test_selfie_and_gps_are_required_on_check_in(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $this->postJson('/api/v1/member/attendance/check-in', ['lat' => 11.0, 'lng' => 76.9])
            ->assertStatus(422)->assertJsonValidationErrors(['selfie']);

        $this->post('/api/v1/member/attendance/check-in', [
            'selfie' => UploadedFile::fake()->image('face.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_geofenced_employee_must_check_in_near_the_branch(): void
    {
        $branch = \App\Models\Branch::create([
            'name' => 'Showroom', 'country' => 'IN', 'is_active' => true,
            'latitude' => 11.0168445, 'longitude' => 76.9558321,
        ]);
        $this->employee->update(['branch_id' => $branch->id]);
        $this->employee->fresh()->employeeProfile->update(['geofence_radius_m' => 200]);

        Sanctum::actingAs($this->employee, ['*']);

        // ~50km away (Erode-ish) → rejected
        $this->post('/api/v1/member/attendance/check-in', [
            'selfie' => UploadedFile::fake()->image('face.jpg'),
            'lat' => 11.34, 'lng' => 77.72,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // at the branch pin → accepted
        $this->post('/api/v1/member/attendance/check-in', [
            'selfie' => UploadedFile::fake()->image('face.jpg'),
            'lat' => 11.0169, 'lng' => 76.9559,
        ], ['Accept' => 'application/json'])->assertStatus(201);
    }

    public function test_month_view_lists_records_and_today(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $this->post('/api/v1/member/attendance/check-in', [
            'selfie' => UploadedFile::fake()->image('face.jpg'),
            'lat' => 11.0, 'lng' => 76.9,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $res = $this->getJson('/api/v1/member/attendance?month=' . now()->format('Y-m'))->assertOk();

        $this->assertSame('EMP-ATT1', $res->json('employee.employee_code'));
        $this->assertSame('present', $res->json('today.status'));
        $this->assertSame(1, $res->json('summary.present'));
        $this->assertCount(1, $res->json('records'));
    }

    public function test_oversized_selfie_is_downscaled_on_save(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $this->post('/api/v1/member/attendance/check-in', [
            'selfie' => UploadedFile::fake()->image('big.jpg', 3000, 4000),
            'lat' => 11.0168445,
            'lng' => 76.9558321,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $employeeId = $this->employee->fresh()->employeeProfile->id;
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get("attendance/{$employeeId}/" . now()->toDateString() . '-in.jpg'));
        $this->assertSame(1024, max($w, $h));
        $this->assertSame(768, min($w, $h));
    }
}
