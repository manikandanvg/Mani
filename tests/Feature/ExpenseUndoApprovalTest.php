<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Board 2026-11-27: "Undo approval" on Report → Expenses, driven through the page itself. */
class ExpenseUndoApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
    }

    protected function expense(string $status): Expense
    {
        return Expense::create(['expense_date' => '2026-04-10', 'category' => 'fuel', 'title' => 'Fuel', 'amount' => 90000,
            'payment_mode' => 'bank', 'status' => $status, 'approved_at' => $status === Expense::APPROVED ? now() : null]);
    }

    public function test_the_row_button_asks_a_reason_and_sends_the_row_back_to_submitted(): void
    {
        $approved = $this->expense(Expense::APPROVED);
        $waiting = $this->expense(Expense::SUBMITTED);

        Livewire::test(ListExpenses::class)
            ->assertTableActionVisible('undoApproval', $approved)
            ->assertTableActionHidden('undoApproval', $waiting)
            ->callTableAction('undoApproval', $approved, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertSame(Expense::APPROVED, $approved->fresh()->status);

        Livewire::test(ListExpenses::class)
            ->callTableAction('undoApproval', $approved, data: ['reason' => 'Wrong amount'])
            ->assertHasNoTableActionErrors();

        $approved->refresh();
        $this->assertSame(Expense::SUBMITTED, $approved->status);
        $this->assertNull($approved->approved_at);
        $this->assertStringContainsString('Wrong amount', $approved->reject_reason);
    }

    public function test_summary_pdf_folds_the_ledger_month_by_month(): void
    {
        $this->expense(Expense::APPROVED);
        $this->expense(Expense::SUBMITTED);
        Expense::create(['expense_date' => '2026-05-02', 'category' => 'asset_vehicles', 'title' => 'Cars', 'amount' => 6000000,
            'payment_mode' => 'bank', 'status' => Expense::APPROVED, 'approved_at' => now()]);

        $s = \App\Filament\Resources\ExpenseResource::summary(Expense::query());
        $this->assertSame(3, $s['count']);
        $this->assertEquals(180000.0, $s['expenses']);
        $this->assertEquals(6000000.0, $s['assets']);
        $this->assertEquals(6090000.0, $s['approved']);
        $this->assertEquals(90000.0, $s['waiting']);
        $this->assertSame('Apr 2026', $s['rows'][0][0]);
        $this->assertSame('2', $s['rows'][0][3]);                 // two fuel rows on one line
        $this->assertSame('TOTAL — Apr 2026', $s['rows'][1][2]);
        $this->assertSame('GRAND TOTAL', end($s['rows'])[2]);

        Livewire::test(ListExpenses::class)->callAction('summaryPdf')->assertFileDownloaded();
    }

    /** Report → Returned Coins lists the coins the admin has confirmed, never a pending return. */
    public function test_returned_coins_report_lists_collected_returns_only(): void
    {
        $branch = \App\Models\Branch::create(['name' => 'RC Branch', 'country' => 'IN', 'is_active' => true]);
        $row = fn (string $no, string $status, array $extra = []) => \App\Models\SalesReturn::create($extra + [
            'return_no' => $no, 'branch_id' => $branch->id, 'material' => 'gold', 'quantity' => 1, 'grams' => 1, 'rate' => 10000,
            'credit_value' => 10000, 'convenience_charge' => 50, 'total_value' => 10050, 'status' => $status]);
        $atHq = $row('SR-T1', \App\Models\SalesReturn::STATUS_COLLECTED, ['bill_no' => 'INV-1', 'collected_at' => now(), 'settled_amount' => 10050, 'settled_at' => now()]);
        $atBranch = $row('SR-T2', \App\Models\SalesReturn::STATUS_COLLECTED, ['collected_at' => now()]);
        $pending = $row('SR-T3', \App\Models\SalesReturn::STATUS_PENDING);

        $page = \App\Filament\Pages\Reports\ReturnedCoins::class;
        Livewire::test($page)
            ->assertCanSeeTableRecords([$atHq, $atBranch])
            ->assertCanNotSeeTableRecords([$pending])
            ->filterTable('went_to', 'hq')
            ->assertCanSeeTableRecords([$atHq])
            ->assertCanNotSeeTableRecords([$atBranch]);

        $this->assertSame('Head Office stock', $page::wentTo($atHq));
        $this->assertSame('Branch stock', $page::wentTo($atBranch));
    }

    public function test_the_bulk_button_undoes_only_the_approved_rows(): void
    {
        $a = $this->expense(Expense::APPROVED);
        $b = $this->expense(Expense::APPROVED);
        $waiting = $this->expense(Expense::SUBMITTED);

        Livewire::test(ListExpenses::class)
            ->callTableBulkAction('undoApprovalSelected', [$a, $b, $waiting], data: ['reason' => 'Board asked'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(0, Expense::where('status', Expense::APPROVED)->count());
        $this->assertNull($waiting->fresh()->reject_reason);
        $this->assertStringContainsString('Board asked', $a->fresh()->reject_reason);
    }
}
