<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Checks\CheckResult;
use Mizan\Runner\Checks\Evidence;
use PHPUnit\Framework\TestCase;

final class CheckResultTest extends TestCase
{
    public function testPassFactorySetsStatusAndNullErrorClass(): void
    {
        $r = CheckResult::pass('composer_install', 10, [], 'ok');
        self::assertSame('pass', $r->status);
        self::assertNull($r->errorClass);
        self::assertSame('ok', $r->message);
    }

    public function testFailFactorySetsStatusAndErrorClassNullable(): void
    {
        $r = CheckResult::fail('app_boots', 5, errorClass: 'bootstrap_threw');
        self::assertSame('fail', $r->status);
        self::assertSame('bootstrap_threw', $r->errorClass);
        self::assertNull($r->message);
    }

    public function testSkipFactoryRequiresErrorClass(): void
    {
        $r = CheckResult::skip('migrations_run', 0, 'env_missing_sqlite');
        self::assertSame('skip', $r->status);
        self::assertSame('env_missing_sqlite', $r->errorClass);
    }

    public function testEvidenceExcerptIsTruncatedAt500Chars(): void
    {
        $long = str_repeat('x', 1000);
        $e = new Evidence(null, null, $long, 'stdout');
        $arr = $e->toArray();
        self::assertSame(500, strlen($arr['excerpt']));
    }

    public function testToArrayShapeMatchesSpec(): void
    {
        $r = CheckResult::fail(
            'readme_real',
            7,
            [new Evidence('README.md', null, 'stub', 'filesystem')],
            'stub_readme',
            'matches Laravel default',
        );
        $arr = $r->toArray();
        self::assertSame('readme_real', $arr['id']);
        self::assertSame('fail', $arr['status']);
        self::assertSame(7, $arr['duration_ms']);
        self::assertCount(1, $arr['evidence']);
        self::assertSame('README.md', $arr['evidence'][0]['file']);
        self::assertSame('filesystem', $arr['evidence'][0]['kind']);
        self::assertSame('stub_readme', $arr['error_class']);
        self::assertSame('matches Laravel default', $arr['message']);
    }
}