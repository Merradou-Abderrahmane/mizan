<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\ComposerInstallCheck;
use Mizan\Runner\Input;
use PHPUnit\Framework\TestCase;

/**
 * @group heavy
 */
final class ComposerInstallCheckTest extends TestCase
{
    use FixtureHelper;

    private function buildInput(string $repo): Input
    {
        return Input::fromArgv(['bin/runner', $repo, '--composer=composer']);
    }

    public function testValidRepoInstallsCleanly(): void
    {
        $result = (new ComposerInstallCheck())->run($this->buildInput($this->fx('valid_repo')));
        self::assertSame('pass', $result->status, $result->message ?? '');
        $this->assertEvidenceHasKind($result, 'stdout');
    }

    public function testBrokenDepsFailsWithStderrEvidence(): void
    {
        $result = (new ComposerInstallCheck())->run($this->buildInput($this->fx('broken_deps_repo')));
        self::assertSame('fail', $result->status);
        self::assertSame('composer_install_failed', $result->errorClass);
        $this->assertEvidenceHasKind($result, 'stderr');
    }

    private function assertEvidenceHasKind(object $r, string $kind): void
    {
        $found = false;
        foreach ($r->evidence as $e) {
            if ($e->kind === $kind) { $found = true; break; }
        }
        self::assertTrue($found, "expected evidence kind=$kind");
    }
}