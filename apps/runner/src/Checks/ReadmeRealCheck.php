<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

use Mizan\Runner\Input;

/**
 * Verifies a real README exists at the repo root (case-insensitive), is at
 * least 200 bytes, and is NOT byte-equal to the bundled Laravel default stub
 * fixture. R5: a fixed threshold, no LLM, no configurable per-brief.
 */
final class ReadmeRealCheck implements Check
{
    /** Minimum README byte length (R5: constant, not configurable). */
    public const MIN_BYTES = 200;

    /** Candidate filenames, case-insensitive. */
    private const CANDIDATES = ['README', 'README.md', 'README.txt', 'README.rst'];

    public function id(): string { return 'readme_real'; }

    public function run(Input $in): CheckResult
    {
        $start = hrtime(true);

        $found = null;
        foreach (self::CANDIDATES as $name) {
            $path = $in->repoPath . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                $found = $name;
                break;
            }
            // Case-insensitive fallback scan (filesystems differ in case behavior).
            foreach (glob($in->repoPath . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT) as $entry) {
                if (is_file($entry) && strcasecmp(basename($entry), $name) === 0) {
                    $found = basename($entry);
                    break 2;
                }
            }
        }

        if ($found === null) {
            return CheckResult::fail(
                $this->id(),
                self::elapsed($start),
                [],
                'readme_missing',
                'no README-like file at repo root',
            );
        }

        $path = $in->repoPath . DIRECTORY_SEPARATOR . $found;
        $bytes = strlen((string) file_get_contents($path));

        if ($bytes < self::MIN_BYTES) {
            return CheckResult::fail(
                $this->id(),
                self::elapsed($start),
                [new Evidence($found, null, "size={$bytes}B (min=" . self::MIN_BYTES . ")", 'filesystem')],
                'readme_too_short',
                "README is {$bytes} bytes, below the " . self::MIN_BYTES . " minimum",
            );
        }

        $stubPath = __DIR__ . '/../../tests/fixtures/laravel_readme_stub.md';
        if (is_file($stubPath) && file_get_contents($path) === file_get_contents($stubPath)) {
            return CheckResult::fail(
                $this->id(),
                self::elapsed($start),
                [new Evidence($found, null, null, 'filesystem')],
                'stub_readme',
                'README is byte-equal to the Laravel default stub',
            );
        }

        return CheckResult::pass(
            $this->id(),
            self::elapsed($start),
            [new Evidence($found, null, "size={$bytes}B", 'filesystem')],
            'real README present',
        );
    }

    private static function elapsed(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1e6);
    }
}