<?php

declare(strict_types=1);

namespace Mizan\Runner;

use Mizan\Runner\Checks\Check;
use Mizan\Runner\Checks\CheckResult;

/**
 * Runner orchestrator — iterates the FIXED ordered list of six checks (R2),
 * catches per-check exceptions into a `fail`/`skip` result, builds the Report,
 * writes the JSON to stdout, and returns the process exit code.
 *
 * R5: boring — no clever dispatch, no per-brief branching.
 */
final class Runner
{
    /** SemVer hardcoded; changing it is a breaking contract change. */
    public const VERSION = '0.1.0';

    /**
     * Fixed order per spec — DO NOT reorder without a new change proposal.
     * Each id maps to a Check implementation registered in buildChecks().
     */
    public const CHECK_ORDER = [
        'composer_install',
        'app_boots',
        'migrations_run',
        'readme_real',
        'env_not_tracked',
        'git_history_real',
    ];

    /** @param list<Check>|null $checks Optional override for tests. */
    public function __construct(private ?array $checks = null) {}

    /**
     * Run all checks against the repo and return the JSON report + exit code.
     *
     * The caller (bin/runner) is responsible for writing the JSON to stdout.
     * This separation keeps Runner unit-testable without output-buffering tricks
     * (fwrite(STDOUT) bypasses ob_start, which would otherwise break assertions).
     *
     * @return array{0:int,1:string} [exitCode, jsonReport]
     */
    public function run(Input $in): array
    {
        $started = $this->nowUtc();
        $startMs = hrtime(true);

        $report = new Report(
            schemaVersion: 1,
            runnerVersion: self::VERSION,
            repoPath: $in->repoPath,
            startedAt: $started,
        );

        foreach ($this->checks ?? $this->buildChecks() as $check) {
            $checkStart = hrtime(true);
            try {
                $result = $check->run($in);
                if ($result->id !== $check->id()) {
                    throw new \LogicException("Check id mismatch: declared {$check->id()}, got {$result->id}");
                }
            } catch (\Throwable $e) {
                $result = CheckResult::fail(
                    $check->id(),
                    $this->elapsedMs($checkStart),
                    errorClass: 'internal_exception',
                    message: $e->getMessage(),
                );
            }
            $report->addCheck($result);
        }

        $report->setDurationMs($this->elapsedMs($startMs));
        $report->setEndedAt($this->nowUtc());

        $exit = $report->status() === 'pass' ? 0 : 1;
        return [$exit, $report->toJson()];
    }

    /**
     * Build the fixed, ordered list of checks for the PHP/Laravel stack.
     * Ordering MUST match CHECK_ORDER. R2: stack-specific, no per-brief logic.
     *
     * @return list<Check>
     */
    private function buildChecks(): array
    {
        return [
            new \Mizan\Runner\Checks\ComposerInstallCheck(),
            new \Mizan\Runner\Checks\AppBootsCheck(),
            new \Mizan\Runner\Checks\MigrationsRunCheck(),
            new \Mizan\Runner\Checks\ReadmeRealCheck(),
            new \Mizan\Runner\Checks\EnvNotTrackedCheck(),
            new \Mizan\Runner\Checks\GitHistoryRealCheck(),
        ];
    }

    private function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function elapsedMs(int $startNt): int
    {
        return (int) round((hrtime(true) - $startNt) / 1e6);
    }
}