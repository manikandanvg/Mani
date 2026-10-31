<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DbSafetyGuard;

    /**
     * The guard must run BEFORE the testing traits: RefreshDatabase's migrate:fresh is
     * executed inside parent::setUp() (setUpTraits), so a check placed after it was too
     * late — on 2026-10-31 a cached config let one class wipe the MySQL dev database before
     * the assertion fired. setUpTraits() runs after the application exists (config readable)
     * and before any trait touches the database.
     */
    protected function setUpTraits(): array
    {
        $this->assertTestDatabaseIsSqlite();   // never let RefreshDatabase touch MySQL

        return parent::setUpTraits();
    }
}
