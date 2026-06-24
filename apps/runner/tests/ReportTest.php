<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\CheckResult;
use Mizan\Runner\Checks\Evidence;
use Mizan\Runner\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public function testAllPassYieldsTopLevelPass(): void
    {
        $r = $this->baseReport();
        foreach (['composer_install','app_boots','migrations_run','readme_real','env_not_tracked','git_history_real'] as $id) {
            $r->addCheck(CheckResult::pass($id, 1));
        }
        $r->setDurationMs(6);
        $r->setEndedAt('2026-01-01T00:00:00.000Z');
        self::assertSame('pass', $r->status());
        $json = $r->toJson();
        $arr = json_decode($json, true);
        self::assertSame('pass', $arr['status']);
        self::assertCount(6, $arr['checks']);
        self::assertSame('composer_install', $arr['checks'][0]['id']);
        self::assertSame('git_history_real', $arr['checks'][5]['id']);
    }

    public function testOneFailYieldsTopLevelFail(): void
    {
        $r = $this->baseReport();
        $r->addCheck(CheckResult::pass('composer_install', 1));
        $r->addCheck(CheckResult::fail('app_boots', 2, [new Evidence(null,null,'boom','stderr')], 'bootstrap_threw'));
        $r->addCheck(CheckResult::pass('migrations_run', 1));
        $r->setDurationMs(4);
        $r->setEndedAt('2026-01-01T00:00:00.000Z');
        self::assertSame('fail', $r->status());
        $arr = json_decode($r->toJson(), true);
        self::assertSame('fail', $arr['status']);
        self::assertSame('fail', $arr['checks'][1]['status']);
    }

    public function testSkippedCheckYieldsTopLevelFail(): void
    {
        $r = $this->baseReport();
        $r->addCheck(CheckResult::pass('composer_install', 1));
        $r->addCheck(CheckResult::skip('migrations_run', 0, 'env_missing_sqlite'));
        $r->setDurationMs(1);
        $r->setEndedAt('2026-01-01T00:00:00.000Z');
        self::assertSame('fail', $r->status());
    }

    public function testErrorStatusEmptiesChecks(): void
    {
        $r = $this->baseReport();
        $r->addCheck(CheckResult::pass('composer_install', 1));
        $r->markError('bad path', 'repo_path_not_found');
        $r->setDurationMs(0);
        $r->setEndedAt('2026-01-01T00:00:00.000Z');
        self::assertSame('error', $r->status());
        $arr = json_decode($r->toJson(), true);
        self::assertSame([], $arr['checks']);
        self::assertSame('repo_path_not_found', $arr['error_class']);
    }

    public function testJsonRoundTripsThroughJsonDecode(): void
    {
        $r = $this->baseReport();
        $r->addCheck(CheckResult::pass('composer_install', 1, [new Evidence(null,null,'ok','stdout')]));
        $r->setDurationMs(1);
        $r->setEndedAt('2026-01-01T00:00:00.000Z');
        $json = $r->toJson();
        $arr = json_decode($json, true);
        self::assertSame(1, $arr['schema_version']);
        self::assertSame('0.1.0', $arr['runner_version']);
        self::assertSame('/repo', $arr['repo_path']);
        self::assertSame(1, $arr['duration_ms']);
        self::assertSame('2026-01-01T00:00:00.000Z', $arr['ended_at']);
    }

    private function baseReport(): Report
    {
        return new Report(
            schemaVersion: 1,
            runnerVersion: '0.1.0',
            repoPath: '/repo',
            startedAt: '2026-01-01T00:00:00.000Z',
        );
    }
}