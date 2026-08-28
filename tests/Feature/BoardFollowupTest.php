<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesReturnResource\Pages\ListSalesReturns;
use App\Filament\Resources\TaskTargetResource\Pages\CreateTaskTarget;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Rank;
use App\Models\SalesReturn;
use App\Models\TaskAssignment;
use App\Models\TaskTarget;
use App\Models\TaskType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** User follow-ups 2026-08-29: admin-only coin confirmation, menu search, nav order, task-target wizard. */
class BoardFollowupTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        TaskType::ensureDefaults();
        $this->get('/admin/login');
    }

    public function test_only_head_office_marks_sales_return_coins_collected(): void
    {
        $dealer = User::where('email', 'distributor@lordicl.com')->firstOrFail();
        $member = Member::create(['member_code' => 'SR1', 'name' => 'SR One', 'phone' => '9111111111', 'joined_on' => now(), 'placement' => 'level',
            'status' => 'active', 'rank_id' => Rank::query()->value('id'), 'branch_id' => $dealer->branch_id]);
        $cp = \App\Models\CatalogProduct::create(['code' => 'C100', 'name' => ['en' => '100 mg coin'], 'material' => 'gold', 'default_weight' => 0.1, 'gst_pct' => 3, 'is_active' => true]);
        $return = SalesReturn::create(['return_no' => 'SR-000001', 'branch_id' => $dealer->branch_id, 'member_id' => $member->id, 'catalog_product_id' => $cp->id,
            'quantity' => 5, 'grams' => 0.5, 'rate' => 8000, 'credit_value' => 4000, 'status' => SalesReturn::STATUS_PENDING, 'collect_on' => now()->addDay()]);

        Livewire::actingAs($dealer)->test(ListSalesReturns::class)->assertTableActionHidden('collect', $return);
        Livewire::actingAs($this->admin)->test(ListSalesReturns::class)->assertTableActionVisible('collect', $return);
    }

    public function test_sidebar_has_a_menu_search_and_monthly_tasks_sits_before_system(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertSuccessful()->assertSee('icl-nav-search');

        $groups = collect(filament()->getPanel('admin')->getNavigationGroups())->map(fn ($g) => is_string($g) ? $g : $g->getLabel())->values();
        $this->assertGreaterThan($groups->search('Support & Track'), $groups->search('Monthly Tasks'));
        $this->assertLessThan($groups->search('System'), $groups->search('Monthly Tasks'));
    }

    public function test_task_target_wizard_writes_one_rule_per_ticked_task_and_can_apply_now(): void
    {
        $rank = Rank::where('depth', 1)->firstOrFail();
        $m = Member::create(['member_code' => 'WZ1', 'name' => 'Wizard One', 'phone' => '9222222222', 'joined_on' => now(), 'placement' => 'level',
            'status' => 'active', 'rank_id' => $rank->id]);
        $direct = TaskType::where('key', 'DIRECT_NEW')->firstOrFail();
        $att = TaskType::where('key', 'ATTENDANCE')->firstOrFail();
        $gbv = TaskType::where('key', 'GBV_GROWTH')->firstOrFail();

        Livewire::actingAs($this->admin)->test(CreateTaskTarget::class)
            ->fillForm([
                'applies' => 'rank', 'rank_id' => $rank->id,
                "tasks.{$direct->id}.on" => true, "tasks.{$direct->id}.target" => 3, "tasks.{$direct->id}.weight" => 2,
                "tasks.{$att->id}.on" => true, "tasks.{$att->id}.target" => 22,
                "tasks.{$gbv->id}.on" => false,
                'apply_now_employee' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rules = TaskTarget::where('rank_id', $rank->id)->get()->keyBy('task_type_id');
        $this->assertCount(2, $rules);
        $this->assertEquals(3, $rules[$direct->id]->target);
        $this->assertSame(2, $rules[$direct->id]->weight);
        $this->assertEquals(22, $rules[$att->id]->target);
        $this->assertFalse($rules->has($gbv->id));
        // Applied to the current month for every member at that stage.
        $this->assertSame(2, TaskAssignment::forSubject('member', $m->id)->count());

        // Re-running for the same stage updates rather than duplicating, and refreshes the live target.
        Livewire::actingAs($this->admin)->test(CreateTaskTarget::class)
            ->fillForm(['applies' => 'rank', 'rank_id' => $rank->id, "tasks.{$direct->id}.on" => true, "tasks.{$direct->id}.target" => 5, 'apply_now_employee' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertCount(2, TaskTarget::where('rank_id', $rank->id)->get());
        $this->assertEquals(5, TaskAssignment::forSubject('member', $m->id)->where('task_type_id', $direct->id)->value('target'));

        // Branch level path.
        $open = TaskType::where('key', 'OPEN_HOURS')->firstOrFail();
        Livewire::actingAs($this->admin)->test(CreateTaskTarget::class)
            ->fillForm(['applies' => 'level', 'branch_level' => 'taluk', "tasks.{$open->id}.on" => true, "tasks.{$open->id}.target" => 26, "tasks.{$open->id}.per_day" => 9])
            ->call('create')->assertHasNoFormErrors();
        $rule = TaskTarget::where('branch_level', 'taluk')->where('task_type_id', $open->id)->firstOrFail();
        $this->assertEquals(9, $rule->per_day);
        $this->assertNull($rule->rank_id);
    }
}
