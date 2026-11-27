<?php

namespace Tests\Feature;

use App\Filament\Resources\BranchResource\Pages\ListBranches;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TableExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * CSV exercises the whole generic pipeline (table columns → per-row state → file) via
     * the globally-injected action. XLSX/PDF share that pipeline and only swap the final
     * writer; their binary downloads can't be asserted through Livewire's JSON test layer
     * (they work in the browser), so CSV is the representative end-to-end check.
     */
    public function test_csv_export_downloads_from_any_table(): void
    {
        Livewire::test(ListBranches::class)
            ->callTableAction('export_csv')
            ->assertFileDownloaded();
    }

    /** PDF must stream (StreamedResponse) so Livewire doesn't JSON-encode binary → 500. */
    public function test_pdf_export_downloads_from_any_table(): void
    {
        Livewire::test(ListBranches::class)
            ->callTableAction('export_pdf')
            ->assertFileDownloaded();
    }

    public function test_print_export_streams_inline(): void
    {
        Livewire::test(ListBranches::class)
            ->callTableAction('export_print')
            ->assertFileDownloaded();
    }

    /** A long table used to run dompdf past the server's time limit (500 on live, 27-11-2026). */
    public function test_pdf_of_a_long_table_is_refused_with_a_message_instead_of_crashing(): void
    {
        $rows = [];
        for ($i = 1; $i <= \App\Filament\Support\TableExport::MAX_PDF_ROWS + 1; $i++) {
            $rows[] = ['expense_no' => 'EXP-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'expense_date' => '2026-04-10', 'category' => 'fuel',
                'kind' => 'expense', 'title' => 'Fuel', 'amount' => 3000, 'payment_mode' => 'bank', 'status' => 'submitted',
                'created_at' => now(), 'updated_at' => now()];
        }
        \Illuminate\Support\Facades\DB::table('expenses')->insert($rows);

        Livewire::test(\App\Filament\Resources\ExpenseResource\Pages\ListExpenses::class)
            ->callTableAction('export_pdf')
            ->assertNoFileDownloaded()
            ->assertNotified('Too many rows for a PDF');

        Livewire::test(\App\Filament\Resources\ExpenseResource\Pages\ListExpenses::class)
            ->callTableAction('export_csv')
            ->assertFileDownloaded();
    }

    public function test_export_actions_are_registered_on_the_table(): void
    {
        Livewire::test(ListBranches::class)
            ->assertTableActionExists('export_csv')
            ->assertTableActionExists('export_xlsx')
            ->assertTableActionExists('export_pdf')
            ->assertTableActionExists('export_print');
    }
}
