<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

use Mizan\Runner\Input;
use Mizan\Runner\Support\CommandRunner;

/**
 * Verifies git history is real: more than one commit. Does NOT consider
 * authors (R5: resist clever checks). Skips when not a git repository.
 */
final class GitHistoryRealCheck implements Check
{
    public function id(): string { return 'git_history_real'; }

    public function run(Input $in): CheckResult
    {
        $start = hrtime(true);
        $runner = new CommandRunner();
        [$exit, $stdout, $stderr] = $runner->run(
            ['git', 'rev-list', '--count', 'HEAD'],
            $in->repoPath,
        );

        if ($exit !== 0) {
            return CheckResult::skip(
                $this->id(),
                self::elapsed($start),
                'not_a_git_repo',
                'git rev-list exited non-zero',
            );
        }

        $count = (int) trim($stdout);
        if ($count > 1) {
            return CheckResult::pass(
                $this->id(),
                self::elapsed($start),
                [new Evidence(null, null, "commits={$count}", 'git')],
                "history has {$count} commits",
            );
        }

        return CheckResult::fail(
            $this->id(),
            self::elapsed($start),
            [new Evidence(null, null, "commits={$count}", 'git')],
            'single_commit_history',
            "history has only {$count} commit",
        );
    }

    private static function elapsed(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1e6);
    }
}