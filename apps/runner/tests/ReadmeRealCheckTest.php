<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\ReadmeRealCheck;
use Mizan\Runner\Input;
use PHPUnit\Framework\TestCase;

final class ReadmeRealCheckTest extends TestCase
{
    use FixtureHelper;

    private function buildInput(string $repo): Input
    {
        return Input::fromArgv(['bin/runner', $repo]);
    }

    /**
     * @group heavy
     */
    public function testValidRepoPassesWithSizeEvidence(): void
    {
        $result = (new ReadmeRealCheck())->run($this->buildInput($this->fx('valid_repo')));
        self::assertSame('pass', $result->status, $result->message ?? '');
        self::assertSame('README.md', $result->evidence[0]->file);
        self::assertStringContainsString('size=', $result->evidence[0]->excerpt ?? '');
    }

    /**
     * @group heavy
     */
    public function testStubReadmeFails(): void
    {
        $result = (new ReadmeRealCheck())->run($this->buildInput($this->fx('stub_readme_repo')));
        self::assertSame('fail', $result->status);
        self::assertSame('stub_readme', $result->errorClass);
        self::assertSame('README.md', $result->evidence[0]->file);
    }

    public function testNoReadmeFailsWithMissingClass(): void
    {
        $result = (new ReadmeRealCheck())->run($this->buildInput($this->fx('no_readme_repo')));
        self::assertSame('fail', $result->status);
        self::assertSame('readme_missing', $result->errorClass);
    }
}