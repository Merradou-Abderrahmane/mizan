<?php

namespace App\Services\Pass1;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A deterministic, bounded, identity-free text bundle of a repo's source, used
 * as the code context for Pass 1. Line numbers in the bundle are 1-based and
 * authoritative for citations; has(file, line) verifies a citation resolves.
 */
class RepoDigest
{
    /** Directory names excluded anywhere in the tree. */
    private const EXCLUDED_DIRS = [
        'vendor', 'node_modules', '.git', 'storage', 'bootstrap',
    ];

    /** Path fragments (relative) excluded. */
    private const EXCLUDED_PREFIXES = [
        'public/build',
    ];

    /** Allowlisted extensions (lowercase, no dot). */
    private const ALLOWED_EXTENSIONS = [
        'php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'json', 'md', 'yml', 'yaml',
        'sql', 'css', 'scss', 'html', 'blade', 'env', 'sh', 'txt',
    ];

    /** @param array<string,int> $lineCounts relPath => number of lines */
    private function __construct(
        public readonly string $text,
        public readonly bool $truncated,
        private readonly array $lineCounts,
    ) {}

    public static function build(string $path, ?int $maxBytes = null): self
    {
        $maxBytes ??= (int) config('grader.digest_max_bytes', 200_000);
        $root = rtrim(str_replace('\\', '/', $path), '/');

        $files = self::collectFiles($root);
        sort($files); // deterministic order

        $tree = "FILE TREE\n";
        foreach ($files as $rel) {
            $tree .= "- {$rel}\n";
        }

        $body = '';
        $truncated = false;
        $lineCounts = [];

        foreach ($files as $rel) {
            $contents = (string) @file_get_contents($root.'/'.$rel);
            $lines = $contents === '' ? [] : explode("\n", str_replace("\r\n", "\n", $contents));
            $lineCounts[$rel] = count($lines);

            $numbered = "### {$rel}\n";
            foreach ($lines as $i => $line) {
                $numbered .= ($i + 1).'| '.$line."\n";
            }
            $numbered .= "\n";

            // Stop at the first file that would exceed the cap (clean prefix).
            if (strlen($tree) + strlen($body) + strlen($numbered) > $maxBytes) {
                $truncated = true;
                unset($lineCounts[$rel]); // not actually included
                break;
            }

            $body .= $numbered;
        }

        return new self($tree."\n".$body, $truncated, $lineCounts);
    }

    /**
     * True when a cited file is included in the digest and the line is in range.
     */
    public function has(string $file, int $line): bool
    {
        $file = ltrim(str_replace('\\', '/', $file), '/');

        return isset($this->lineCounts[$file]) && $line >= 1 && $line <= $this->lineCounts[$file];
    }

    /** @return list<string> */
    private static function collectFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');

            if (self::isExcluded($rel) || ! self::isAllowed($rel)) {
                continue;
            }

            $files[] = $rel;
        }

        return $files;
    }

    private static function isExcluded(string $rel): bool
    {
        $segments = explode('/', $rel);

        // Any excluded directory anywhere, or a dot-directory.
        foreach (array_slice($segments, 0, -1) as $segment) {
            if (in_array($segment, self::EXCLUDED_DIRS, true) || str_starts_with($segment, '.')) {
                return true;
            }
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isAllowed(string $rel): bool
    {
        $name = basename($rel);

        // .env.example and similar: allow when the final extension is allowlisted.
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::ALLOWED_EXTENSIONS, true);
    }
}
