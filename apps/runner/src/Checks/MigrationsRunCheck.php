<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

use Mizan\Runner\Input;
use Mizan\Runner\Support\CommandRunner;

/**
 * Runs `php artisan migrate --force` against a throwaway SQLite DB created
 * inside <repoPath>/storage/ and deleted afterward. Skips when pdo_sqlite is
 * not loaded. Does NOT mutate the student's .env — DB config is overridden via
 * process env vars (DB_CONNECTION, DB_DATABASE), which Laravel honors at boot.
 *
 * Per design.md D3: file-backed SQLite under the repo workdir to keep Laravel
 * migration tooling happy and stay containerizable.
 */
class MigrationsRunCheck implements Check
{
    public function id(): string { return 'migrations_run'; }

    public function run(Input $in): CheckResult
    {
        $start = hrtime(true);

        if (!$this->isSqliteAvailable()) {
            return CheckResult::skip(
                $this->id(),
                self::elapsed($start),
                'env_missing_sqlite',
                'pdo_sqlite extension not loaded',
            );
        }

        $storage = rtrim($in->repoPath, '\\/') . '/storage';
        if (!is_dir($storage)) {
            @mkdir($storage, 0775, true);
        }
        $dbPath = $storage . '/runner-sqlite-' . getmypid() . '.sqlite';

        try {
            // Override Laravel's DB at runtime via env vars — no .env mutation.
            $env = [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $dbPath,
            ];
            $runner = new CommandRunner();
            [$exit, $stdout, $stderr] = $runner->run(
                [PHP_BINARY, 'artisan', 'migrate', '--force', '--no-ansi'],
                $in->repoPath,
                $env,
            );
            $ms = self::elapsed($start);

            if ($exit === 0) {
                return CheckResult::pass(
                    $this->id(),
                    $ms,
                    [new Evidence(null, null, self::tail($stdout), 'stdout')],
                    'migrations ran cleanly',
                );
            }

            // artisan writes to stdout even on failure; capture both. Extract a
            // cited migration file + line where available (R3-grade citation).
            $combined = $stderr . "\n" . $stdout;
            [$file, $line] = self::extractFileLine($combined);
            $excerpt = self::tail($stdout !== '' ? $stdout : $stderr);
            return CheckResult::fail(
                $this->id(),
                $ms,
                [new Evidence($file, $line, $excerpt, $stdout !== '' ? 'stdout' : 'stderr')],
                'migrations_threw',
                'migrate exited non-zero',
            );
        } finally {
            if (file_exists($dbPath)) {
                @unlink($dbPath);
            }
        }
    }

    /**
     * Sqlite-availability seam. Overridable in tests to simulate a host
     * without the pdo_sqlite extension. Kept protected (not public) on
     * purpose: it's an internal seam, not part of the Check contract.
     */
    protected function isSqliteAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    private static function elapsed(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1e6);
    }

    /**
     * Best-effort extract of <file>:<line> from an artisan migrate error
     * (Laravel exceptions cite paths like ".../migrations/0002_xxx.php:12").
     * @return array{0:?string,1:?int}
     */
    private static function extractFileLine(string $output): array
    {
        // Match `database/migrations/foo.php[:line]` on either slash direction.
        if (preg_match('#database[/\\\\]migrations[/\\\\][^:\s"\'<>]+\.php(?::(\d+))?#i', $output, $m)) {
            $raw = $m[0];
            $line = isset($m[1]) ? (int) $m[1] : null;
            $file = isset($m[1]) ? substr($raw, 0, -(strlen(':' . $m[1]))) : $raw;
            return [self::relativize($file), $line];
        }
        return [null, null];
    }

    private static function relativize(string $path): string
    {
        // Strip absolute prefix up to "database/migrations".
        $marker = 'database/migrations/';
        $pos = strpos($path, $marker);
        return $pos === false ? $path : substr($path, $pos);
    }

    private static function tail(string $s): string
    {
        $s = trim($s);
        return strlen($s) > 500 ? substr($s, -500) : $s;
    }
}