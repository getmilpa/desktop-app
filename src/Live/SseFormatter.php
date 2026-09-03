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

namespace Milpa\DesktopApp\Live;

/**
 * Renders shell events as `text/event-stream` records a browser's `EventSource` reads (greenhouse decisions/0188).
 *
 * Pure formatting, no I/O: the {@see preamble()} opens the stream (a keep-alive comment + the reconnect
 * `retry` hint), and each {@see event()} is one SSE record carrying its `id` (so the client resumes with
 * `Last-Event-ID`), its `event` name, and its `data` JSON. Kept as small pure pieces because
 * {@see \Milpa\DesktopApp\Controllers\EventsController} now streams them one at a time over a live
 * connection (via the runtime's CallbackStream, evidence/0472) rather than formatting one batch and closing.
 */
final class SseFormatter
{
    /** Milliseconds a browser waits before reconnecting after the connection closes. */
    public const RETRY_MS = 2000;

    /** The stream preamble: a keep-alive comment (valid even before any event) plus the retry hint. */
    public function preamble(): string
    {
        return ': keep-alive' . "\n" . 'retry: ' . self::RETRY_MS . "\n\n";
    }

    /** One SSE record: its id (for Last-Event-ID resumption), its event name, and its data JSON. */
    public function event(int $id, ShellEvent $event): string
    {
        return 'id: ' . $id . "\n"
            . 'event: ' . $event->type . "\n"
            . 'data: ' . $event->toJson() . "\n\n";
    }
}
