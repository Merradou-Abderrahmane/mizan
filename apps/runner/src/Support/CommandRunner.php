<?php

declare(strict_types=1);

namespace Mizan\Runner\Support;

use Symfony\Component\Process\Process;

/**
 * Tiny wrapper around Symfony\Process for running a command in a directory and
 * capturing stdout/stderr/exit-code. Kept minimal on purpose (R5: boring).
 */
final class CommandRunner
{
    /** Run a command and return (exitCode, stdout, stderr). */
    public function run(array $command, string $cwd, ?array $env = null, ?float $timeout = 120.0): array
    {
        $proc = new Process($command, $cwd, $env, null, $timeout);
        $proc->run();
        return [
            $proc->getExitCode(),
            self::stripAnsi($proc->getOutput()),
            self::stripAnsi($proc->getErrorOutput()),
        ];
    }

    private static function stripAnsi(string $s): string
    {
        return preg_replace('/\e\[[0-9;]*m/', '', $s) ?? $s;
    }
}