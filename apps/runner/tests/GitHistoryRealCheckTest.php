<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\GitHistoryRealCheck;
use Mizan\Runner\Input;
use PHPUnit\Framework\TestCase;

final class GitHistoryRealCheckTest extends TestCase
{
    use FixtureHelper;

    private function buildInput(string $repo): Input
    {
        return Input::fromArgv(['bin/runner', $repo]);
    }

    /**
     * @group heavy
     */
    public function testValidRepoPassesWithCommitCount(): void
    {
        $result = (new GitHistoryRealCheck())->run($this->buildInput($this->fx('valid_repo')));
        self::assertSame('pass', $result->status);
        self::assertStringContainsString('commits=', $result->evidence[0]->excerpt ?? '');
    }

    public function testSingleCommitFails(): void
    {
        $result = (new GitHistoryRealCheck())->run($this->buildInput($this->fx('single_commit_repo')));
        self::assertSame('fail', $result->status);
        self::assertSame('single_commit_history', $result->errorClass);
    }

    public function testNonGitDirSkips(): void
    {
        // Create a dir truly outside any git repo (the mizan repo would otherwise
        // satisfy `git rev-list` for a nested child dir).
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mizan_nogit_' . uniqid();
        @mkdir($tmp);
        file_put_contents($tmp . DIRECTORY_SEPARATOR . 'composer.json', '{}');
        try {
            $result = (new GitHistoryRealCheck())->run($this->buildInput($tmp));
        } finally {
            @unlink($tmp . DIRECTORY_SEPARATOR . 'composer.json');
            @rmdir($tmp);
        }
        self::assertSame('skip', $result->status);
        self::assertSame('not_a_git_repo', $result->errorClass);
    }
}