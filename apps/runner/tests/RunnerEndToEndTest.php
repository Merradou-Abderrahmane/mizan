<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the bin/runner entry point end-to-end as a real subprocess against the
 * heavy fixtures, asserting the JSON Report contract from specs/runner-cli/spec.md
 * and the matching exit codes.
 *
 * @group heavy
 */
final class RunnerEndToEndTest extends TestCase
{
    use FixtureHelper;

    private function runnerPath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'runner';
    }

    private function invoke(array $args): array
    {
        $proc = new Process(array_merge(['php', $this->runnerPath()], $args), null, null, null, 300.0);
        $proc->run();
        return [$proc->getExitCode(), $proc->getOutput(), $proc->getErrorOutput()];
    }

    public function testValidRepoExits0AndStatusPass(): void
    {
        [$exit, $stdout] = $this->invoke([$this->fx('valid_repo')]);
        self::assertSame(0, $exit, "exit code 0 expected; stderr present? check fixtures\n");
        $arr = json_decode($stdout, true);
        self::assertSame('pass', $arr['status']);
        self::assertCount(6, $arr['checks']);
        // Fixed-order contract.
        $ids = array_column($arr['checks'], 'id');
        self::assertSame(\Mizan\Runner\Runner::CHECK_ORDER, $ids);
    }

    public function testStubReadmeRepoExits1StatusFailOnlyReadmeFails(): void
    {
        [$exit, $stdout] = $this->invoke([$this->fx('stub_readme_repo')]);
        self::assertSame(1, $exit);
        $arr = json_decode($stdout, true);
        self::assertSame('fail', $arr['status']);
        $byId = [];
        foreach ($arr['checks'] as $c) { $byId[$c['id']] = $c['status']; }
        self::assertSame('fail', $byId['readme_real']);
        // Only readme_real fails; every other check passes.
        unset($byId['readme_real']);
        foreach ($byId as $id => $status) {
            self::assertSame('pass', $status, "unexpected fail for $id");
        }
    }

    public function testNonExistentPathExits1StatusErrorEmptyChecks(): void
    {
        [$exit, $stdout] = $this->invoke([sys_get_temp_dir() . '/mizan_no_such_dir_' . uniqid()]);
        self::assertSame(1, $exit);
        $arr = json_decode($stdout, true);
        self::assertSame('error', $arr['status']);
        self::assertSame([], $arr['checks']);
        self::assertSame('repo_path_not_found', $arr['error_class']);
    }
}