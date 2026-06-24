<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

use Mizan\Runner\Input;
use Mizan\Runner\Support\CommandRunner;

/**
 * Runs `php artisan --version` inside the student repo. Passes when the
 * command exits 0 and stdout contains a Laravel version line. Fails with
 * cited stdout/stderr otherwise. Never skips.
 */
final class AppBootsCheck implements Check
{
    public function id(): string { return 'app_boots'; }

    public function run(Input $in): CheckResult
    {
        $start = hrtime(true);
        $runner = new CommandRunner();
        [$exit, $stdout, $stderr] = $runner->run(
            [PHP_BINARY, 'artisan', '--version', '--no-ansi'],
            $in->repoPath,
        );
        $ms = self::elapsed($start);

        $hasVersion = $exit === 0 && str_contains($stdout, 'Laravel Framework');
        if ($hasVersion) {
            // Extract the matching line for crisp evidence.
            $line = self::firstMatchingLine($stdout, 'Laravel Framework');
            return CheckResult::pass(
                $this->id(),
                $ms,
                [new Evidence(null, null, $line, 'stdout')],
                'app booted',
            );
        }
        $excerpt = trim($stderr . "\n" . $stdout);
        return CheckResult::fail(
            $this->id(),
            $ms,
            [new Evidence(null, null, self::tail($excerpt), 'stderr')],
            'app_boot_failed',
            'artisan --version did not produce a Laravel version line',
        );
    }

    private static function elapsed(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1e6);
    }

    private static function firstMatchingLine(string $haystack, string $needle): string
    {
        foreach (preg_split('/\r?\n/', $haystack) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }
        return trim($haystack);
    }

    private static function tail(string $s): string
    {
        $s = trim($s);
        return strlen($s) > 500 ? substr($s, -500) : $s;
    }
}