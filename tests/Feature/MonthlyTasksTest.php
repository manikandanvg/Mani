<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchAttendance;
use App\Models\BranchStockDay;
use App\Models\CatalogProduct;
use App\Models\CommissionLedger;
use App\Models\Device;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVisit;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use App\Models\MemberMonthSnapshot;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RdEntry;
use App\Models\SalesInvoice;
use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use App\Models\Stock;
use App\Models\TaskAssignment;
use App\Models\TaskScore;
use App\Models\TaskSubmission;
use App\Models\TaskTarget;
use App\Models\TaskType;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Tasks\TaskEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Monthly Tasks Engine (board 2026-08-28 / answers 2026-08-29): rules per TBP stage and
 * branch level roll into assignments, the engine measures them from existing ledgers,
 * scores lock on the 1st and scale GAP + payroll (CBC exempt), the app reads it all.
 */
class MonthlyTasksTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Branch $branch;
    protected Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->branch = Branch::where('level', '!=', 'hq')->firstOrFail();
        $this->branch->update(['level' => 'taluk', 'is_active' => true]);
        $this->month = Carbon::now()->startOfMonth();
        TaskType::ensureDefaults();
        $this->get('/admin/login');
    }

    protected function member(string $code, int $depth = 1, ?int $uplineId = null): Member
    {
        $m = Member::create([
            'member_code' => $code, 'name' => "Member {$code}", 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'upline_id' => $uplineId,
            'rank_id' => Rank::where('depth', $depth)->firstOrFail()->id,
        ]);
        MemberWallet::create(['member_id' => $m->id]);

        return $m;
    }

    protected function rule(string $key, ?int $rankId = null, ?string $level = null, float $target = 1, ?float $perDay = null, int $weight = 1): TaskTarget
    {
        return TaskTarget::create([
            'task_type_id' => TaskType::where('key', $key)->firstOrFail()->id,
            'rank_id' => $rankId, 'branch_level' => $level, 'target' => $target, 'per_day' => $perDay, 'weight' => $weight, 'is_active' => true,
        ]);
    }

    protected function assignment(string $type, int $id, string $key): TaskAssignment
    {
        return TaskAssignment::forMonth($this->month)->forSubject($type, $id)
            ->whereHas('taskType', fn ($q) => $q->where('key', $key))->firstOrFail();
    }

    // ── Roll ─────────────────────────────────────────────────────────────────────

    public function test_rules_per_rank_and_level_roll_into_assignments_with_gbv_baseline(): void
    {
        $rank = Rank::where('depth', 1)->firstOrFail();
        $this->rule('DIRECT_NEW', rankId: $rank->id, target: 2);
        $this->rule('OPEN_DAYS', level: 'taluk', target: 20);
        $taluk = $this->member('T1', 1);
        $plain = $this->member('P0', 0);

        $n = app(TaskEngine::class)->rollMonth($this->month);

        $this->assertSame(2, $n);   // one for the taluk admin, one for the taluk branch
        $this->assertSame(1, TaskAssignment::forSubject('member', $taluk->id)->count());
        $this->assertSame(0, TaskAssignment::forSubject('member', $plain->id)->count());
        $this->assertSame(1, TaskAssignment::forSubject('branch', $this->branch->id)->count());
        $this->assertNotNull(MemberMonthSnapshot::where('member_id', $taluk->id)->whereDate('month', $this->month)->first());
        // Idempotent.
        $this->assertSame(0, app(TaskEngine::class)->rollMonth($this->month));
    }

    // ── Branch measurers ─────────────────────────────────────────────────────────

    public function test_branch_open_hours_stock_rd_and_billing_are_measured(): void
    {
        $this->rule('OPEN_HOURS', level: 'taluk', target: 2, perDay: 8);
        $this->rule('STOCK_KEPT', level: 'taluk', target: 0);
        $this->rule('RD_RENEWALS', level: 'taluk', target: 2);
        $this->rule('BILLING_G10', level: 'taluk', target: 1000);
        $engine = app(TaskEngine::class);
        $engine->rollMonth($this->month);
        $b = $this->branch;
        $d1 = $this->month->copy()->day(2);
        $d2 = $this->month->copy()->day(3);
        $d3 = $this->month->copy()->day(4);

        // Day 1: 9 hours (counts). Day 2: 3 hours (short). Day 3: opened, never closed → auto-close at 8 PM = 10 h.
        BranchAttendance::create(['branch_id' => $b->id, 'date' => $d1, 'opened_at' => $d1->copy()->setTime(9, 0), 'closed_at' => $d1->copy()->setTime(18, 0)]);
        BranchAttendance::create(['branch_id' => $b->id, 'date' => $d2, 'opened_at' => $d2->copy()->setTime(10, 0), 'closed_at' => $d2->copy()->setTime(13, 0)]);
        BranchAttendance::create(['branch_id' => $b->id, 'date' => $d3, 'opened_at' => $d3->copy()->setTime(10, 0), 'closed_at' => null]);
        $this->assertSame(1, $engine->autoClose($d3));
        $this->assertSame('20:00', BranchAttendance::whereDate('date', $d3->toDateString())->first()->closed_at->format('H:i'));

        // Stock: coin at 3 pcs against Opening 5 → short; snapshot twice on two days.
        $coin = CatalogProduct::create(['code' => 'G1X', 'name' => ['en' => 'Gold coin 1 g'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $b->id, 'catalog_product_id' => $coin->id, 'quantity' => 3, 'min_qty' => 5]);
        $engine->snapshotStock($d1);
        Stock::where('branch_id', $b->id)->update(['quantity' => 6]);
        $engine->snapshotStock($d2);
        $this->assertTrue(BranchStockDay::where('branch_id', $b->id)->whereDate('date', $d1)->first()->is_short);
        $this->assertFalse(BranchStockDay::where('branch_id', $b->id)->whereDate('date', $d2)->first()->is_short);

        // RD + G10 billing.
        $m = $this->member('R1', 0);
        $plan = Plan::create(['code' => 'P206T', 'name' => ['en' => 'G10 Gold'], 'plan_type' => 1, 'type' => 'gold', 'min_value' => 0, 'allocation_bv' => 100,
            'validity_months' => 0, 'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => [], 'level_schedule' => [], 'billing_margin' => 0, 'is_active' => true]);
        $bond = Bond::create(['member_id' => $m->id, 'plan_id' => $plan->id, 'bond_date' => $d1, 'value' => 1000, 'status' => 'active']);
        RdEntry::create(['bond_id' => $bond->id, 'member_id' => $m->id, 'paid_on' => $d1, 'value' => 500, 'branch_id' => $b->id]);
        SalesInvoice::create(['invoice_no' => 'INV-T1', 'date' => $d1, 'customer_member_id' => $m->id, 'branch_id' => $b->id, 'plan_id' => $plan->id,
            'cross_total' => 1500, 'net_total' => 1500]);

        $engine->measure($this->month);

        $open = $this->assignment('branch', $b->id, 'OPEN_HOURS');
        $this->assertEquals(2, $open->achieved);          // day 1 + auto-closed day 3
        $this->assertEquals(100, $open->pct);
        $this->assertSame('achieved', $open->status);

        $stock = $this->assignment('branch', $b->id, 'STOCK_KEPT');
        $this->assertEquals(1, $stock->achieved);         // one shortfall day
        $this->assertEquals(90, $stock->pct);             // 10 points per day over the allowed 0

        $this->assertEquals(1, $this->assignment('branch', $b->id, 'RD_RENEWALS')->achieved);
        $this->assertEquals(1500, $this->assignment('branch', $b->id, 'BILLING_G10')->achieved);

        // Chart: day 1 red, day 2 green, other days grey.
        $chart = $engine->stockChart($b, $this->month);
        $this->assertSame(1, $chart['shortfall_days']);
        $this->assertTrue($chart['series'][1]['short']);
        $this->assertFalse($chart['series'][2]['short']);
        $this->assertFalse($chart['series'][10]['checked']);
    }

    // ── Employee measurers ───────────────────────────────────────────────────────

    public function test_employee_tasks_are_measured_from_existing_ledgers(): void
    {
        $rank = Rank::where('depth', 1)->firstOrFail();
        foreach (['ATTENDANCE' => 2, 'BRANCH_VISITS' => 1, 'ZOOM_JOINED' => 1, 'ZOOM_MINUTES' => 30, 'GENERAL_MEETINGS' => 1,
            'DIRECT_NEW' => 1, 'GBV_GROWTH' => 500, 'MEET_PERSON' => 1] as $key => $target) {
            $this->rule($key, rankId: $rank->id, target: $target);
        }
        $m = $this->member('E1', 1);
        $m->update(['gbv' => 1000]);
        $engine = app(TaskEngine::class);
        $engine->rollMonth($this->month);
        $d = $this->month->copy()->day(5);

        $emp = EmployeeProfile::create(['member_id' => $m->id, 'employee_code' => 'EMP-E1', 'date_of_joining' => now()->subYear(), 'status' => 'active']);
        // Attendance: two full days, one check-in only.
        foreach ([2, 3] as $day) {
            \App\Models\AttendanceRecord::create(['employee_profile_id' => $emp->id, 'date' => $this->month->copy()->day($day), 'status' => 'present',
                'check_in_at' => $this->month->copy()->day($day)->setTime(9, 0), 'check_out_at' => $this->month->copy()->day($day)->setTime(18, 0)]);
        }
        \App\Models\AttendanceRecord::create(['employee_profile_id' => $emp->id, 'date' => $this->month->copy()->day(4), 'status' => 'present',
            'check_in_at' => $this->month->copy()->day(4)->setTime(9, 0)]);

        // Branch visit at another branch's box + arena tap for an L-BOX general meeting.
        $box = Device::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Box A', 'serial_no' => 'SN-A', 'branch_id' => $this->branch->id]);
        $arena = Device::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Arena', 'serial_no' => 'SN-AR']);
        EmployeeVisit::create(['employee_profile_id' => $emp->id, 'device_id' => $box->id, 'branch_id' => $this->branch->id, 'visited_at' => $d->copy()->setTime(11, 0)]);
        EmployeeVisit::create(['employee_profile_id' => $emp->id, 'device_id' => $box->id, 'branch_id' => $this->branch->id, 'visited_at' => $d->copy()->setTime(16, 0)]);  // same day → still 1
        $general = Meeting::create(['title' => 'General', 'platform' => 'lbox', 'device_id' => $arena->id, 'scheduled_at' => $d->copy()->setTime(18, 0),
            'duration_min' => 60, 'visibility' => 'members', 'is_published' => true]);
        EmployeeVisit::create(['employee_profile_id' => $emp->id, 'device_id' => $arena->id, 'branch_id' => null, 'visited_at' => $d->copy()->setTime(17, 30)]);

        // Zoom: 60-minute meeting, 35 verified minutes (≥ 50 %) + a 10-minute one (not verified).
        $z1 = Meeting::create(['title' => 'Z1', 'platform' => 'zoom', 'join_url' => 'https://zoom.us/j/1', 'scheduled_at' => $d, 'duration_min' => 60, 'visibility' => 'members', 'is_published' => true]);
        $z2 = Meeting::create(['title' => 'Z2', 'platform' => 'zoom', 'join_url' => 'https://zoom.us/j/2', 'scheduled_at' => $d, 'duration_min' => 60, 'visibility' => 'members', 'is_published' => true]);
        MeetingAttendance::create(['meeting_id' => $z1->id, 'member_id' => $m->id, 'source' => 'zoom', 'joined_at' => $d, 'left_at' => $d->copy()->addMinutes(35), 'duration_min' => 35]);
        MeetingAttendance::create(['meeting_id' => $z2->id, 'member_id' => $m->id, 'source' => 'zoom', 'joined_at' => $d, 'left_at' => $d->copy()->addMinutes(10), 'duration_min' => 10]);

        // Direct new member, GBV growth, photo post.
        $this->member('D1', 0, uplineId: $m->id);
        $m->update(['gbv' => 1800]);
        $post = SocialPost::create(['body' => 'Met Mr Kumar at Trichy', 'visibility' => 'members', 'poster_type' => Member::class, 'poster_id' => $m->id]);
        SocialPostMedia::create(['post_id' => $post->id, 'path' => 'social/x.jpg', 'type' => 'image']);
        SocialPost::create(['body' => 'text only', 'visibility' => 'members', 'poster_type' => Member::class, 'poster_id' => $m->id]);

        $engine->measure($this->month);

        $get = fn (string $k) => (float) $this->assignment('member', $m->id, $k)->achieved;
        $this->assertEquals(2, $get('ATTENDANCE'));
        $this->assertEquals(1, $get('BRANCH_VISITS'));
        $this->assertEquals(1, $get('ZOOM_JOINED'));
        $this->assertEquals(45, $get('ZOOM_MINUTES'));
        $this->assertEquals(1, $get('GENERAL_MEETINGS'));
        $this->assertEquals(1, $get('DIRECT_NEW'));
        $this->assertEquals(800, $get('GBV_GROWTH'));
        $this->assertEquals(1, $get('MEET_PERSON'));
    }

    // ── Lock, score, pay ─────────────────────────────────────────────────────────

    public function test_lock_writes_weighted_scores_and_scales_gap_and_payroll_but_not_cbc(): void
    {
        $rank = Rank::where('depth', 1)->firstOrFail();
        $this->rule('DIRECT_NEW', rankId: $rank->id, target: 2, weight: 3);   // achieved 1 of 2 → 50 %
        $this->rule('ATTENDANCE', rankId: $rank->id, target: 10, weight: 1);   // 0 %
        $this->rule('ZOOM_INVITED', rankId: $rank->id, target: 0, weight: 0);  // info only
        $last = Carbon::now()->subMonth()->startOfMonth();
        $upline = $this->member('U1', 1);
        $this->member('U1D', 0, uplineId: $upline->id)->update(['joined_on' => $last->copy()->day(3)]);
        $engine = app(TaskEngine::class);
        $engine->rollMonth($last);

        $n = $engine->lockMonth($last);

        $this->assertSame(1, $n);
        $score = TaskScore::where('subject_type', 'member')->where('subject_id', $upline->id)->whereDate('month', $last)->firstOrFail();
        $this->assertEquals(37.5, $score->score_pct);          // (50×3 + 0×1) / 4
        $this->assertSame('locked', $score->status);
        $this->assertSame('missed', TaskAssignment::forMonth($last)->forSubject('member', $upline->id)->whereHas('taskType', fn ($q) => $q->where('key', 'DIRECT_NEW'))->firstOrFail()->status);
        $this->assertEquals(0.375, TaskScore::factorFor($upline->id, $last));
        $this->assertEquals(1.0, TaskScore::factorFor($upline->id, Carbon::now()));   // no score this month → untouched

        // GAP for the month just ended is scaled by that score; CBC is not.
        $plan = Plan::create(['code' => 'PGAP', 'name' => ['en' => 'GAP plan'], 'type' => 'rd', 'min_value' => 1000, 'allocation_bv' => 100, 'validity_months' => 11,
            'cbc_value' => 100, 'cbc_count' => 3, 'ic_schedule' => [], 'level_schedule' => ['10'], 'epin_count' => 0, 'is_active' => true]);
        Bond::create(['member_id' => $upline->id, 'plan_id' => $plan->id, 'bond_date' => now(), 'value' => 1000, 'lvlcom_count' => 0, 'status' => 'active']);
        $down = Member::where('member_code', 'U1D')->firstOrFail();
        $bond = Bond::create(['member_id' => $down->id, 'plan_id' => $plan->id, 'bond_date' => now(), 'value' => 1000, 'lvlcom_count' => 11, 'cbc_value' => 100, 'cbc_count' => 3, 'status' => 'active']);

        app(CommissionService::class)->runGap(Carbon::now());
        app(CommissionService::class)->issueCbc(Carbon::now());

        $this->assertEquals(37.5, CommissionLedger::where('member_id', $upline->id)->where('type', 'GAP')->sum('amount'));   // 10 % of 1000 × 0.375
        $this->assertEquals(100, \App\Models\CbcEntry::where('bond_id', $bond->id)->sum('worth'));                            // CBC untouched

        // Payroll gross for that month is scaled too.
        $emp = EmployeeProfile::create(['member_id' => $upline->id, 'employee_code' => 'EMP-U1', 'date_of_joining' => now()->subYear(), 'monthly_salary' => 20000,
            'pf_enabled' => false, 'esi_enabled' => false, 'tds_pct' => 0, 'status' => 'active']);
        \App\Models\AttendanceRecord::create(['employee_profile_id' => $emp->id, 'date' => $last->copy()->day(2), 'status' => 'present']);
        $run = app(\App\Services\Payroll\PayrollService::class)->generate($last->year, $last->month, $this->admin->id);
        $slip = \App\Models\Payslip::where('payroll_run_id', $run->id)->where('employee_profile_id', $emp->id)->firstOrFail();
        $this->assertEquals(37.5, $slip->snapshot['task_score_pct']);
        $this->assertEquals(round(20000 * 1 / $slip->snapshot['working_days'] * 0.375, 2), (float) $slip->gross);
    }

    // ── Manual tasks + app ───────────────────────────────────────────────────────

    public function test_app_reads_tasks_and_submits_proof_that_hq_verifies(): void
    {
        Notification::fake();
        Storage::fake('local');
        $rank = Rank::where('depth', 1)->firstOrFail();
        $this->rule('TOWN_VISIT', rankId: $rank->id, target: 2);
        $m = $this->member('A1', 1);
        $engine = app(TaskEngine::class);
        $engine->rollMonth($this->month);
        $custom = $engine->assignManual('member', $m->id, TaskType::where('key', 'CUSTOM')->firstOrFail(), $this->month, 1, 2, 'Visit the Karur showroom', null, $this->admin->id);

        Sanctum::actingAs($m, ['*']);
        $res = $this->getJson('/api/v1/member/tasks')->assertOk()->json();
        $this->assertSame($this->month->format('Y-m'), $res['month']);
        $this->assertCount(2, $res['employee_tasks']);
        $this->assertSame('Visit the Karur showroom', collect($res['employee_tasks'])->firstWhere('key', 'CUSTOM')['title']);
        $this->assertNull($res['branch']);

        $visit = $this->assignment('member', $m->id, 'TOWN_VISIT');
        $this->postJson("/api/v1/member/tasks/{$visit->id}/submit", [])->assertStatus(422);
        $this->post("/api/v1/member/tasks/{$visit->id}/submit", [
            'body' => 'Visited Karur town, met 3 prospects', 'lat' => 10.96, 'lng' => 78.08,
            'photo' => UploadedFile::fake()->image('visit.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $sub = TaskSubmission::firstOrFail();
        Storage::disk('local')->assertExists($sub->photo_path);
        $this->assertSame(1, $this->getJson('/api/v1/member/tasks')->json('employee_tasks.0.pending_submissions') + $this->getJson('/api/v1/member/tasks')->json('employee_tasks.1.pending_submissions'));
        $this->assertEquals(0, $visit->fresh()->achieved);

        // Another member cannot submit against it; auto tasks refuse submissions.
        $other = $this->member('A2', 1);
        Sanctum::actingAs($other, ['*']);
        $this->postJson("/api/v1/member/tasks/{$visit->id}/submit", ['body' => 'x'])->assertForbidden();

        // HQ verifies → counts.
        Livewire::actingAs($this->admin)->test(\App\Filament\Resources\TaskSubmissionResource\Pages\ListTaskSubmissions::class)
            ->callTableAction('verify', $sub, data: ['value' => 1, 'review_note' => 'ok'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('verified', $sub->fresh()->status);
        $this->assertEquals(1, $visit->fresh()->achieved);
        $this->assertEquals(50, $visit->fresh()->pct);
        $this->actingAs($this->admin)->get(route('task.proof', $sub->id))->assertOk();
    }

    public function test_branch_tasks_and_downlines_appear_for_the_dealer_who_runs_the_branch(): void
    {
        $this->rule('OPEN_DAYS', level: 'taluk', target: 20);
        $rank = Rank::where('depth', 1)->firstOrFail();
        $this->rule('DIRECT_NEW', rankId: $rank->id, target: 1);
        $dealer = User::where('email', 'distributor@lordicl.com')->firstOrFail();
        $dealer->update(['branch_id' => $this->branch->id]);
        $m = $this->member('DL1', 1);
        $dealer->update(['member_code' => $m->member_code]);
        $child = $this->member('DL2', 1, uplineId: $m->id);
        app(TaskEngine::class)->rollMonth($this->month);

        Sanctum::actingAs($m, ['*']);
        $res = $this->getJson('/api/v1/member/tasks')->assertOk()->json();
        $this->assertSame($this->branch->name, $res['branch']['name']);
        $this->assertCount(1, $res['branch']['tasks']);
        $this->assertSame('DL2', $res['downlines'][0]['member_code']);
        $this->getJson('/api/v1/member/tasks/stock-chart')->assertOk()->assertJsonPath('branch.id', $this->branch->id);

        // Dealer login sees only its own rows in the admin.
        Livewire::actingAs($dealer)->test(\App\Filament\Resources\TaskAssignmentResource\Pages\ListTaskAssignments::class)
            ->assertCanSeeTableRecords(TaskAssignment::forSubject('branch', $this->branch->id)->get())
            ->assertCanNotSeeTableRecords(TaskAssignment::forSubject('member', $child->id)->get());
    }

    public function test_admin_screens_render_and_ranks_page_carries_the_task_rules(): void
    {
        foreach (['task-types', 'task-types/create', 'task-targets', 'task-targets/create', 'task-assignments', 'task-submissions', 'task-scores', 'stock-tracking', 'meetings/create'] as $slug) {
            $this->actingAs($this->admin)->get("/admin/{$slug}")->assertSuccessful();
        }
        $rank = Rank::where('depth', 1)->firstOrFail();
        $this->actingAs($this->admin)->get("/admin/ranks/{$rank->id}/edit")->assertSuccessful();

        Livewire::actingAs($this->admin)->test(\App\Filament\Resources\RankResource\RelationManagers\TaskTargetsRelationManager::class, [
            'ownerRecord' => $rank, 'pageClass' => \App\Filament\Resources\RankResource\Pages\EditRank::class,
        ])->callTableAction('add_all');
        $this->assertSame(9, TaskTarget::where('rank_id', $rank->id)->count());   // the nine auto employee types
        $this->assertSame(0, TaskTarget::where('rank_id', $rank->id)->whereHas('taskType', fn ($q) => $q->where('scope', 'branch'))->count());
    }
}
