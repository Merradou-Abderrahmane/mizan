<?php

declare(strict_types=1);

namespace Mizan\Runner;

use Mizan\Runner\Checks\CheckResult;

/**
 * The JSON report emitted by the runner. Matches the exact schema pinned in
 * openspec/changes/runner-foundation-v0/specs/runner-cli/spec.md.
 *
 * Top-level status semantics (per spec):
 *   pass  — all checks have status pass.
 *   fail  — at least one check has status fail, none has error.
 *   error — runner could not run (bad path, internal). checks MAY be empty.
 */
final class Report
{
    /** @var list<CheckResult> */
    private array $checks = [];
    private bool $errored = false;
    private ?string $errorMessage = null;
    private ?string $errorClass = null;

    public function __construct(
        public int $schemaVersion,
        public string $runnerVersion,
        public string $repoPath,
        public string $startedAt,
        private ?string $endedAt = null,
        private ?int $durationMs = null,
    ) {
    }

    public function addCheck(CheckResult $result): void
    {
        $this->checks[] = $result;
    }

    /**
     * Mark the whole run as errored (runner could not start). When errored,
     * `checks` is treated as empty per spec, regardless of prior additions.
     */
    public function markError(string $message, string $errorClass): self
    {
        $this->errored = true;
        $this->errorMessage = $message;
        $this->errorClass = $errorClass;
        return $this;
    }

    public function status(): string
    {
        if ($this->errored) {
            return 'error';
        }
        foreach ($this->checks as $c) {
            // Per spec: pass only when every check is "pass"; a skip is not a pass.
            if ($c->status === 'fail' || $c->status === 'skip') {
                return 'fail';
            }
        }
        return 'pass';
    }

    /** Set the ended timestamp before serializing. */
    public function setEndedAt(string $endedAt): void
    {
        $this->endedAt = $endedAt;
    }

    public function setDurationMs(int $ms): void
    {
        $this->durationMs = $ms;
    }

    public function toJson(): string
    {
        $endedAt = $this->endedAt ?? $this->startedAt;
        $durationMs = $this->durationMs ?? 0;

        $payload = [
            'schema_version' => $this->schemaVersion,
            'status' => $this->status(),
            'runner_version' => $this->runnerVersion,
            'repo_path' => $this->repoPath,
            'started_at' => $this->startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'checks' => $this->errored
                ? []
                : array_map(static fn(CheckResult $c) => $c->toArray(), $this->checks),
        ];
        if ($this->errored) {
            $payload['error_class'] = $this->errorClass;
            $payload['message'] = $this->errorMessage;
        }
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}