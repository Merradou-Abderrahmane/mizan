<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

use Mizan\Runner\Input;
use Mizan\Runner\Support\CommandRunner;

/**
 * Verifies `.env` is not tracked by git. `.env.example` is allowed. Skips
 * (not fails) when the path is not a git repository.
 */
final class EnvNotTrackedCheck implements Check
{
    public function id(): string { return 'env_not_tracked'; }

    public function run(Input $in): CheckResult
    {
        $start = hrtime(true);
        $runner = new CommandRunner();
        [$exit, $stdout, $stderr] = $runner->run(
            ['git', 'ls-files'],
            $in->repoPath,
        );

        if ($exit !== 0) {
            return CheckResult::skip(
                $this->id(),
                self::elapsed($start),
                'not_a_git_repo',
                'git ls-files exited non-zero',
            );
        }

        $tracked = preg_split('/\r?\n/', trim($stdout));
        foreach ($tracked as $path) {
            if ($path === '.env') {
                return CheckResult::fail(
                    $this->id(),
                    self::elapsed($start),
                    [new Evidence('.env', null, null, 'git')],
                    'env_committed',
                    '.env is tracked by git',
                );
            }
        }

        return CheckResult::pass(
            $this->id(),
            self::elapsed($start),
            [new Evidence(null, null, 'not tracked', 'git')],
            '.env is not tracked',
        );
    }

    private static function elapsed(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1e6);
    }
}