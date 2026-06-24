<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\EnvNotTrackedCheck;
use Mizan\Runner\Input;
use PHPUnit\Framework\TestCase;

final class EnvNotTrackedCheckTest extends TestCase
{
    use FixtureHelper;

    private function buildInput(string $repo): Input
    {
        return Input::fromArgv(['bin/runner', $repo]);
    }

    /**
     * @group heavy
     */
    public function testValidRepoPasses(): void
    {
        $result = (new EnvNotTrackedCheck())->run($this->buildInput($this->fx('valid_repo')));
        self::assertSame('pass', $result->status);
    }

    public function testEnvCommittedRepoFailsCitingEnvFile(): void
    {
        $result = (new EnvNotTrackedCheck())->run($this->buildInput($this->fx('env_committed_repo')));
        self::assertSame('fail', $result->status);
        self::assertSame('env_committed', $result->errorClass);
        self::assertSame('.env', $result->evidence[0]->file);
    }

    public function testNonGitDirSkips(): void
    {
        // Create a dir truly outside any git repo (the mizan repo would otherwise
        // satisfy `git ls-files` for a nested child dir).
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mizan_nogit_' . uniqid();
        @mkdir($tmp);
        file_put_contents($tmp . DIRECTORY_SEPARATOR . 'composer.json', '{}');
        try {
            $result = (new EnvNotTrackedCheck())->run($this->buildInput($tmp));
        } finally {
            @unlink($tmp . DIRECTORY_SEPARATOR . 'composer.json');
            @rmdir($tmp);
        }
        self::assertSame('skip', $result->status);
        self::assertSame('not_a_git_repo', $result->errorClass);
    }
}