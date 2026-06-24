<?php

declare(strict_types=1);

namespace Mizan\Runner;

/**
 * Input is the validated, immutable description of a single runner invocation.
 *
 * Containerizable-without-API-change constraint (see openspec/changes/
 * runner-foundation-v0/design.md): the only per-invocation input is the repo
 * path passed as a positional argument. The runner reads NO environment
 * variables except those listed on the allowlist below (currently empty).
 * No host paths are baked in.
 */
final class Input
{
    /** Explicit env allowlist. Empty by design — see design.md D2. */
    private const ENV_ALLOWLIST = [];

    public string $repoPath;
    public ?string $composerBinary;
    public ?string $workdir;

    private function __construct(string $repoPath, ?string $composerBinary, ?string $workdir)
    {
        $this->repoPath = $repoPath;
        $this->composerBinary = $composerBinary;
        $this->workdir = $workdir;
    }

    /**
     * Parse raw CLI arguments into a validated Input.
     *
     * @param array<int,string> $argv
     * @throws InvalidInput When repoPath is missing or not a directory.
     */
    public static function fromArgv(array $argv): self
    {
        $positional = [];
        $composer = null;
        $workdir = null;

        $argc = count($argv);
        for ($i = 1; $i < $argc; $i++) {
            $arg = $argv[$i];
            if ($arg === '--') {
                continue;
            }
            if (str_starts_with($arg, '--composer=')) {
                $composer = substr($arg, strlen('--composer='));
                continue;
            }
            if (str_starts_with($arg, '--workdir=')) {
                $workdir = substr($arg, strlen('--workdir='));
                continue;
            }
            $positional[] = $arg;
        }

        if (count($positional) === 0) {
            throw InvalidInput::missingRepoPath();
        }

        $raw = $positional[0];
        $resolved = realpath($raw);
        if ($resolved === false || !is_dir($resolved)) {
            throw InvalidInput::repoPathNotFound($raw);
        }

        return new self($resolved, $composer, $workdir);
    }

    /**
     * Guard against environment reads not on the allowlist.
     * Usage: $value = Input::env('SOME_VAR') ?? 'default'.
     *
     * @return ?string
     */
    public static function env(string $name): ?string
    {
        if (!in_array($name, self::ENV_ALLOWLIST, true)) {
            return null;
        }
        $value = getenv($name);
        return $value === false ? null : $value;
    }
}