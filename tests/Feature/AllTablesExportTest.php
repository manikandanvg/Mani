<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-10-31 (common 7): "some data-tables were not working properly — check the
 * pagination and export of every table". Every admin resource list must render its second
 * page and stream a CSV export without error.
 */
class AllTablesExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_resource_list_paginates_and_exports(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->actingAs($admin);
        $this->get('/admin/login');

        $failures = [];
        foreach (filament()->getPanel('admin')->getResources() as $resource) {
            $slug = $resource::getSlug();
            try {
                $this->get($resource::getUrl('index') . '?page=2')->assertSuccessful();
            } catch (\Throwable $e) {
                $failures[] = "{$slug} page 2: " . mb_substr($e->getMessage(), 0, 160);
            }
            $index = $resource::getPages()['index'] ?? null;
            if (! $index) {
                continue;
            }
            try {
                Livewire::test($index->getPage())
                    ->callTableAction('export_csv')
                    ->assertHasNoTableActionErrors()
                    ->assertFileDownloaded();
            } catch (\Throwable $e) {
                $failures[] = "{$slug} export: " . mb_substr($e->getMessage(), 0, 160);
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
