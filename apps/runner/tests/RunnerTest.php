<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\Check;
use Mizan\Runner\Checks\CheckResult;
use Mizan\Runner\Checks\Evidence;
use Mizan\Runner\Input;
use Mizan\Runner\Runner;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    public function testAllStubChecksPassExit0JsonValid(): void
    {
        $checks = $this->sixStubs(fn(string $id) => CheckResult::pass($id, 1, [new Evidence(null, null, 'ok', 'stdout')]));
        [$json, $exit] = $this->runWith($checks, sys_get_temp_dir());
        self::assertSame(0, $exit);
        $arr = json_decode($json, true);
        self::assertSame('pass', $arr['status']);
        self::assertCount(6, $arr['checks']);
        $ids = array_column($arr['checks'], 'id');
        self::assertSame(Runner::CHECK_ORDER, $ids);
    }

    public function testAnyStubFailExits1(): void
    {
        $checks = $this->sixStubs(fn(string $id) => $id === 'app_boots'
            ? CheckResult::fail($id, 1, errorClass: 'boom')
            : CheckResult::pass($id, 1));
        [$json, $exit] = $this->runWith($checks, sys_get_temp_dir());
        self::assertSame(1, $exit);
        $arr = json_decode($json, true);
        self::assertSame('fail', $arr['status']);
        self::assertSame('fail', $arr['checks'][1]['status']);
    }

    public function testCheckThrowingBecomesFailInternalException(): void
    {
        $checks = [
            new ThrowingCheck('composer_install', new \RuntimeException('explode')),
            ...$this->sixStubs(fn(string $id) => CheckResult::pass($id, 1), 5, 1),
        ];
        [$json, $exit] = $this->runWith($checks, sys_get_temp_dir());
        self::assertSame(1, $exit);
        $arr = json_decode($json, true);
        self::assertSame('fail', $arr['status']);
        self::assertSame('internal_exception', $arr['checks'][0]['error_class']);
        self::assertSame('explode', $arr['checks'][0]['message']);
    }

    public function testStdoutContainsOnlyJsonNoExtraBytes(): void
    {
        $checks = $this->sixStubs(fn(string $id) => CheckResult::pass($id, 0));
        [$json, $exit] = $this->runWith($checks, sys_get_temp_dir());
        self::assertSame(0, $exit);
        json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue(true);
    }

    /**
     * @param list<Check> $checks
     * @return array{0:string,1:int}
     */
    private function runWith(array $checks, string $repoPath): array
    {
        // Use the public factory so tests construct Input the same way bin/runner does.
        $input = Input::fromArgv(['bin/runner', $repoPath]);
        [$exit, $json] = (new Runner($checks))->run($input);
        return [$json, $exit];
    }

    /**
     * @param callable(string $id): CheckResult $factory
     * @return list<Check>
     */
    private function sixStubs(callable $factory): array
    {
        $checks = [];
        foreach (Runner::CHECK_ORDER as $id) {
            $checks[] = new StubCheck($id, $factory($id));
        }
        return $checks;
    }
}

final class StubCheck implements Check
{
    public function __construct(private string $id, private CheckResult $result) {}
    public function id(): string { return $this->id; }
    public function run(Input $in): CheckResult { return $this->result; }
}

final class ThrowingCheck implements Check
{
    public function __construct(private string $id, private \Throwable $e) {}
    public function id(): string { return $this->id; }
    public function run(Input $in): CheckResult { throw $this->e; }
}