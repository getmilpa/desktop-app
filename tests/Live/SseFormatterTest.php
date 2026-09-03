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
 * The SSE wire pieces a browser's EventSource reads (greenhouse decisions/0188): the preamble opens the
 * stream with a retry hint, and each event carries its id (for Last-Event-ID resumption), name, and data.
 */
final class SseFormatterTest extends TestCase
{
    public function testThePreambleOpensTheStreamWithARetryHint(): void
    {
        $preamble = (new SseFormatter())->preamble();

        self::assertStringContainsString(': keep-alive', $preamble);
        self::assertStringContainsString('retry: ' . SseFormatter::RETRY_MS, $preamble);
    }

    public function testAnEventCarriesItsIdNameAndData(): void
    {
        $record = (new SseFormatter())->event(7, new ShellEvent('badge.updated', ['text' => 'hi']));

        self::assertSame('id: 7' . "\n" . 'event: badge.updated' . "\n" . 'data: {"text":"hi"}' . "\n\n", $record);
    }

    public function testAnEventWithoutDataStillRendersValidJson(): void
    {
        self::assertStringContainsString('data: []', (new SseFormatter())->event(1, new ShellEvent('panel.closed')));
    }
}
