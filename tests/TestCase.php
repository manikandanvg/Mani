<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DbSafetyGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestDatabaseIsSqlite();   // never let RefreshDatabase touch MySQL
    }
}
