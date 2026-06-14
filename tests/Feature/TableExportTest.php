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

    public function test_export_actions_are_registered_on_the_table(): void
    {
        Livewire::test(ListBranches::class)
            ->assertTableActionExists('export_csv')
            ->assertTableActionExists('export_xlsx')
            ->assertTableActionExists('export_pdf')
            ->assertTableActionExists('export_print');
    }
}
