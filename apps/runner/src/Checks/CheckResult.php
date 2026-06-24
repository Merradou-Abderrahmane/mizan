<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

/**
 * Immutable result of a single structural check. Matches the per-check JSON
 * shape pinned in openspec/changes/runner-foundation-v0/specs/runner-cli/spec.md.
 */
final class CheckResult
{
    /** @var list<Evidence> */
    public array $evidence;

    private function __construct(
        public string $id,
        public string $status, // "pass" | "fail" | "skip"
        public int $durationMs,
        array $evidence,
        public ?string $errorClass,
        public ?string $message,
    ) {
        $this->evidence = array_values($evidence);
    }

    public static function pass(string $id, int $durationMs, array $evidence = [], ?string $message = null): self
    {
        return new self($id, 'pass', $durationMs, $evidence, null, $message);
    }

    public static function fail(string $id, int $durationMs, array $evidence = [], ?string $errorClass = null, ?string $message = null): self
    {
        return new self($id, 'fail', $durationMs, $evidence, $errorClass, $message);
    }

    public static function skip(string $id, int $durationMs, string $errorClass, ?string $message = null, array $evidence = []): self
    {
        return new self($id, 'skip', $durationMs, $evidence, $errorClass, $message);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'evidence' => array_map(static fn(Evidence $e) => $e->toArray(), $this->evidence),
            'error_class' => $this->errorClass,
            'message' => $this->message,
        ];
    }
}