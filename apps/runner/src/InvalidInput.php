<?php

declare(strict_types=1);

namespace Mizan\Runner;

/**
 * Raised when the runner cannotconstruct a valid Input from CLI args.
 * The bin entrypoint catches this to emit stderr + exit 2 (missing arg)
 * or stdout-error-json + exit 1 (repo path not a dir).
 */
final class InvalidInput extends \InvalidArgumentException
{
    public static function missingRepoPath(): self
    {
        return new self('Missing required argument: <repoPath>', 2);
    }

    public static function repoPathNotFound(string $raw): self
    {
        return new self("Repo path not found or not a directory: {$raw}", 1);
    }
}