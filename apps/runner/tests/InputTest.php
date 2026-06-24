<?php

declare(strict_types=1);

namespace Mizan\Runner\Tests;

use Mizan\Runner\Input;
use Mizan\Runner\InvalidInput;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testMissingRepoPathArgThrowsCode2(): void
    {
        $this->expectException(InvalidInput::class);
        try {
            Input::fromArgv(['bin/runner']);
        } catch (InvalidInput $e) {
            self::assertSame(2, $e->getCode());
            throw $e;
        }
    }

    public function testNonDirectoryPathThrowsCode1(): void
    {
        $this->expectException(InvalidInput::class);
        try {
            Input::fromArgv(['bin/runner', '/no/such/dir/anywhere']);
        } catch (InvalidInput $e) {
            self::assertSame(1, $e->getCode());
            throw $e;
        }
    }

    public function testValidDirectoryConstructsAndResolvesRealPath(): void
    {
        $tmp = sys_get_temp_dir();
        $input = Input::fromArgv(['bin/runner', $tmp]);
        self::assertSame(realpath($tmp), $input->repoPath);
        self::assertNull($input->composerBinary);
        self::assertNull($input->workdir);
    }

    public function testComposerAndWorkdirFlagsAreParsed(): void
    {
        $tmp = sys_get_temp_dir();
        $input = Input::fromArgv(['bin/runner', $tmp, '--composer=/usr/local/bin/composer', '--workdir=/tmp/w']);
        self::assertSame('/usr/local/bin/composer', $input->composerBinary);
        self::assertSame('/tmp/w', $input->workdir);
    }

    public function testEnvReadOffAllowlistReturnsNull(): void
    {
        // Allowlist is empty by design; any env read MUST return null.
        putenv('MIZAN_TEST_SECRET=leak');
        self::assertNull(Input::env('MIZAN_TEST_SECRET'));
        putenv('MIZAN_TEST_SECRET');
    }
}