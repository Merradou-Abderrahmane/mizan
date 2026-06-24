<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

use Mizan\Runner\Input;
use Mizan\Runner\Support\CommandRunner;

/**
 * Runs `composer install --no-interaction --ignore-platform-reqs` inside the
 * student repo. Never skips. Captures stdout/stderr excerpts as evidence.
 */
final class ComposerInstallCheck implements Check
{
    public function id(): string { return 'composer_install'; }

    public function run(Input $in): CheckResult
    {
        $start = hrtime(true);
        $composer = $in->composerBinary ?? 'composer';
        $runner = new CommandRunner();
        [$exit, $stdout, $stderr] = $runner->run(
            [$composer, 'install', '--no-interaction', '--ignore-platform-reqs', '--no-ansi'],
            $in->repoPath,
        );
        $ms = self::elapsed($start);

        if ($exit === 0) {
            return CheckResult::pass(
                $this->id(),
                $ms,
                [new Evidence(null, null, self::tail($stdout), 'stdout')],
                'composer install succeeded',
            );
        }
        return CheckResult::fail(
            $this->id(),
            $ms,
            [new Evidence(null, null, self::tail($stderr ?: $stdout), 'stderr')],
            'composer_install_failed',
            'composer install exited non-zero',
        );
    }

    private static function elapsed(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1e6);
    }

    private static function tail(string $s): string
    {
        $s = trim($s);
        return strlen($s) > 500 ? substr($s, -500) : $s;
    }
}