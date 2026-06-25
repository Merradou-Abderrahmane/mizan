<?php

namespace App\Support;

use SplFileObject;
use Throwable;

/**
 * Reads a single source line, read-only, from a run's repository on disk (design
 * D6). The Pass 1 evidence row stores only the VERIFIED citation coordinates
 * (file + line) plus the model's note; the actual source text is not persisted.
 * The detail screen reconstructs the excerpt here so what the operator sees is
 * real repository source, never the model's claim. Returns null when the source
 * is unavailable (e.g. a URL intake whose clone was removed) — the caller then
 * omits the excerpt rather than fabricating one.
 */
class SourceExcerpt
{
    public static function line(?string $root, ?string $file, ?int $line): ?string
    {
        if ($root === null || $root === '' || $file === null || $file === '' || $line === null || $line < 1) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $rel = ltrim(str_replace('\\', '/', $file), '/');

        $realRoot = realpath($root);
        $realPath = realpath($root.'/'.$rel);

        // Unavailable, or resolves outside the repo root (traversal guard).
        if ($realRoot === false || $realPath === false) {
            return null;
        }
        $realRoot = str_replace('\\', '/', $realRoot);
        $realPath = str_replace('\\', '/', $realPath);
        if (! str_starts_with($realPath, $realRoot.'/') && $realPath !== $realRoot) {
            return null;
        }
        if (! is_file($realPath)) {
            return null;
        }

        try {
            $f = new SplFileObject($realPath);
            $f->seek($line - 1);
            $text = $f->current();
        } catch (Throwable) {
            return null;
        }

        if (! is_string($text)) {
            return null;
        }

        return rtrim($text, "\r\n");
    }
}
