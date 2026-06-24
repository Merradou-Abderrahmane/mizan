<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Shared helper for resolving heavy/light fixture paths and skipping the test
 * when a fixture is absent (e.g., a fresh clone that hasn't run the setup
 * scripts yet). Keeps tests honest without hiding real regressions.
 */
trait FixtureHelper
{
    private function fx(string $name): string
    {
        $path = implode(DIRECTORY_SEPARATOR, [dirname(__DIR__), 'tests', 'fixtures', $name]);
        if (!is_dir($path) && !is_file($path)) {
            self::markTestSkipped("Fixture missing: $name (run bin/setup-heavy-fixtures.ps1 and bin/setup-light-fixtures.ps1)");
        }
        return $path;
    }
}