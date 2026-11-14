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
    /**
     * Most fixtures bill plain gold / silver items with 0 % making and wastage to keep their
     * arithmetic round. Production (2026-11-14) gives such an item its Charge Bracket, or
     * refuses it — so that rule is OFF by default here and switched on by the tests that are
     * about it (SalesPageChargesTest).
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['features.metal_charge_fallback' => false]);
    }

    protected function setUpTraits(): array
    {
        $this->assertTestDatabaseIsSqlite();   // never let RefreshDatabase touch MySQL

        return parent::setUpTraits();
    }
}
