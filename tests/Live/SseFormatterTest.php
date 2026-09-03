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
use Milpa\DesktopApp\Live\SseFormatter;
use PHPUnit\Framework\TestCase;

/**
 * The SSE wire format a browser's EventSource reads (greenhouse decisions/0188): each event carries its
 * id (for Last-Event-ID resumption), its event name, and its data JSON.
 */
final class SseFormatterTest extends TestCase
{
    public function testItRendersEachEventWithIdNameAndData(): void
    {
        $body = (new SseFormatter())->format([
            ['id' => 1, 'event' => new ShellEvent('badge.updated', ['text' => 'hi'])],
            ['id' => 2, 'event' => new ShellEvent('panel.closed')],
        ]);

        self::assertStringContainsString('id: 1' . "\n" . 'event: badge.updated' . "\n" . 'data: {"text":"hi"}', $body);
        self::assertStringContainsString('id: 2' . "\n" . 'event: panel.closed' . "\n" . 'data: []', $body);
        // Retry hint keeps the short-poll reconnect cadence honest.
        self::assertStringContainsString('retry: ' . SseFormatter::RETRY_MS, $body);
    }

    public function testAnEmptyFeedIsStillAValidStream(): void
    {
        $body = (new SseFormatter())->format([]);

        // A comment line keeps the stream valid with zero events (and never confuses EventSource).
        self::assertStringContainsString(': keep-alive', $body);
        self::assertStringNotContainsString('event:', $body);
    }
}
