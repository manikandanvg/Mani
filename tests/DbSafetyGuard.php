<?php

namespace Tests;

/**
 * Refuses to run the suite against anything but the in-memory SQLite the
 * phpunit.xml env declares. On 2026-09-21 a cached config (`php artisan
 * config:cache`) made the suite run RefreshDatabase against the MySQL dev
 * database and wiped it. A cached config wins over phpunit's <env> values,
 * so the check has to be made at runtime, not in the XML.
 */
trait DbSafetyGuard
{
    protected function assertTestDatabaseIsSqlite(): void
    {
        $conn = config('database.default');
        $db = config("database.connections.{$conn}.database");
        if ($conn !== 'sqlite' || $db !== ':memory:') {
            fwrite(STDERR, "\n*** REFUSING TO RUN TESTS: database is [{$conn}] [{$db}], not the in-memory sqlite. "
                . "Run `php artisan config:clear` (a cached config overrides phpunit.xml) and try again. ***\n\n");
            exit(1);
        }
    }
}
