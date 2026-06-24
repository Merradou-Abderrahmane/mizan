<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\CheckResult;
use Mizan\Runner\Checks\MigrationsRunCheck;
use Mizan\Runner\Input;
use PHPUnit\Framework\TestCase;

/**
 * @group heavy
 */
final class MigrationsRunCheckTest extends TestCase
{
    use FixtureHelper;

    private function buildInput(string $repo): Input
    {
        return Input::fromArgv(['bin/runner', $repo]);
    }

    public function testValidRepoMigrationsRunAndSqliteFileIsDeleted(): void
    {
        $repo = $this->fx('valid_repo');
        $result = (new MigrationsRunCheck())->run($this->buildInput($repo));
        self::assertSame('pass', $result->status, $result->message ?? '');
        // No runner-sqlite leftovers in storage/.
        $leftovers = glob($repo . '/storage/runner-sqlite-*.sqlite');
        self::assertSame([], $leftovers ?: [], 'SQLite file must be cleaned up after the check');
    }

    public function testBrokenMigrationFailsAndCitesMigrationFile(): void
    {
        $result = (new MigrationsRunCheck())->run($this->buildInput($this->fx('broken_migration_repo')));
        self::assertSame('fail', $result->status);
        self::assertSame('migrations_threw', $result->errorClass);
        // The failing migration path SHOULD be cited (R3-grade).
        $file = $result->evidence[0]->file ?? null;
        self::assertNotNull($file, 'evidence must cite the failing migration file');
        self::assertStringContainsString('mizan_broken', $file);
    }

    public function testSkipWhenSqliteExtensionMissing(): void
    {
        // Subclass seam: override isSqliteAvailable to simulate a host without pdo_sqlite.
        $check = new class extends MigrationsRunCheck {
            protected function isSqliteAvailable(): bool { return false; }
        };
        $result = $check->run($this->buildInput($this->fx('valid_repo')));
        self::assertSame('skip', $result->status);
        self::assertSame('env_missing_sqlite', $result->errorClass);
    }
}