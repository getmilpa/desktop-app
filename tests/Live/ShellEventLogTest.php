<?php

/**
 * This file is part of milpa/desktop-app — a Milpa app hosts itself as a desktop app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/desktop-app
 */

declare(strict_types=1);

namespace Milpa\DesktopApp\Tests\Live;

use Milpa\DesktopApp\Live\ShellEvent;
use Milpa\DesktopApp\Live\ShellEventLog;
use PHPUnit\Framework\TestCase;

/**
 * The shared bus that carries shell events between requests (greenhouse decisions/0188): append in one
 * request, read since a cursor in another. The cursor is what lets an SSE client resume without missing
 * or repeating events.
 */
final class ShellEventLogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-desktop-log-' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAnAppendedEventIsReadableSinceZeroWithIdOne(): void
    {
        $log = new ShellEventLog($this->path);
        $log->append(new ShellEvent('badge.updated', ['text' => 'hi']));

        $entries = $log->since(0);
        self::assertCount(1, $entries);
        self::assertSame(1, $entries[0]['id']);
        self::assertSame('badge.updated', $entries[0]['event']->type);
        self::assertSame(['text' => 'hi'], $entries[0]['event']->data);
    }

    public function testTheCursorReturnsOnlyLaterEvents(): void
    {
        $log = new ShellEventLog($this->path);
        $log->append(new ShellEvent('one'));
        $log->append(new ShellEvent('two'));
        $log->append(new ShellEvent('three'));

        $entries = $log->since(1);
        self::assertSame([2, 3], array_map(static fn (array $e): int => $e['id'], $entries));
        self::assertSame(['two', 'three'], array_map(static fn (array $e): string => $e['event']->type, $entries));

        self::assertSame([], $log->since(3), 'nothing after the last id');
    }

    public function testAnAbsentLogReadsAsEmpty(): void
    {
        self::assertSame([], (new ShellEventLog($this->path))->since(0));
    }

    public function testItCreatesTheLogDirectoryOnFirstAppend(): void
    {
        $nested = sys_get_temp_dir() . '/milpa-desktop-' . uniqid('', true) . '/events/log.ndjson';
        $log = new ShellEventLog($nested);

        $log->append(new ShellEvent('created'));

        self::assertFileExists($nested);
        self::assertSame('created', $log->since(0)[0]['event']->type);

        // Clean up the created tree.
        unlink($nested);
        rmdir(\dirname($nested));
        rmdir(\dirname($nested, 2));
    }
}
