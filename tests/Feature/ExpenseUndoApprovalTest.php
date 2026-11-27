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
