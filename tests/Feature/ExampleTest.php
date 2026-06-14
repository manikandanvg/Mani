<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** Root now serves the public storefront home. */
    public function test_root_serves_storefront(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->get('/')->assertSuccessful();
    }
}
