<?php

namespace App\Services;

use RuntimeException;

class RunnerCrashException extends RuntimeException
{
    public readonly string $rawStdout;
    public readonly string $repoPath;

    public function __construct(string $rawStdout, string $repoPath)
    {
        $this->rawStdout = $rawStdout;
        $this->repoPath = $repoPath;

        parent::__construct("Runner produced invalid JSON on stdout.");
    }
}