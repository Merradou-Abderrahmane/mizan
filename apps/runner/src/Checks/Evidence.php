<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

/**
 * One piece of evidence cited by a check. Per spec: file+line SHALL be null
 * when not applicable; SHALL be set when a check makes a file-level claim
 * (R3-grade citation readiness). Excerpt capped at 500 chars.
 */
final class Evidence
{
    public function __construct(
        public ?string $file,
        public ?int $line,
        public ?string $excerpt,
        public string $kind, // "stdout"|"stderr"|"git"|"filesystem"|"command"
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'excerpt' => $this->excerpt === null ? null : self::truncate($this->excerpt, 500),
            'kind' => $this->kind,
        ];
    }

    private static function truncate(string $s, int $max): string
    {
        return strlen($s) <= $max ? $s : substr($s, 0, $max);
    }
}