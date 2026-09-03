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
use PHPUnit\Framework\TestCase;

/**
 * The shell event value object (greenhouse decisions/0188): its JSON is what a browser reads, and it
 * round-trips through the log line the shared bus stores.
 */
final class ShellEventTest extends TestCase
{
    public function testItRoundTripsThroughItsLogLine(): void
    {
        $event = new ShellEvent('panel.opened', ['id' => 'gate-1', 'nested' => ['ok' => true]]);

        $restored = ShellEvent::fromLogLine($event->toLogLine());

        self::assertSame('panel.opened', $restored->type);
        self::assertSame(['id' => 'gate-1', 'nested' => ['ok' => true]], $restored->data);
    }

    public function testAMalformedLineDecodesToAnEmptyEventRatherThanCrashing(): void
    {
        // A line missing type/data (e.g. a hand-truncated log) must not take the feed down.
        $restored = ShellEvent::fromLogLine('{"unexpected":1}');

        self::assertSame('', $restored->type);
        self::assertSame([], $restored->data);
    }

    public function testANonObjectLineAlsoDecodesToAnEmptyEvent(): void
    {
        // Valid JSON that is not an object (a bare scalar) must not crash the feed either.
        $restored = ShellEvent::fromLogLine('42');

        self::assertSame('', $restored->type);
        self::assertSame([], $restored->data);
    }

    public function testDataBecomesTheJsonABrowserReads(): void
    {
        self::assertSame('{"text":"hi"}', (new ShellEvent('x', ['text' => 'hi']))->toJson());
    }
}
