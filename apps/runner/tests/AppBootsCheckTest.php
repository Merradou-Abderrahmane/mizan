<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\AppBootsCheck;
use Mizan\Runner\Input;
use PHPUnit\Framework\TestCase;

/**
 * @group heavy
 */
final class AppBootsCheckTest extends TestCase
{
    use FixtureHelper;

    private function buildInput(string $repo): Input
    {
        return Input::fromArgv(['bin/runner', $repo]);
    }

    public function testValidRepoBootsWithLaravelVersionLine(): void
    {
        $result = (new AppBootsCheck())->run($this->buildInput($this->fx('valid_repo')));
        self::assertSame('pass', $result->status, $result->message ?? '');
        // Evidence excerpt MUST contain "Laravel Framework" (R3-grade citation).
        $excerpt = $result->evidence[0]->excerpt ?? '';
        self::assertStringContainsString('Laravel Framework', $excerpt);
        self::assertSame('stdout', $result->evidence[0]->kind);
    }

    public function testBrokenBootstrapFails(): void
    {
        $result = (new AppBootsCheck())->run($this->buildInput($this->fx('broken_bootstrap_repo')));
        self::assertSame('fail', $result->status);
        self::assertSame('app_boot_failed', $result->errorClass);
        // Evidence MUST be cited.
        self::assertNotEmpty($result->evidence);
    }
}