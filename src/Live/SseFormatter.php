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
 * Renders shell events as a `text/event-stream` body a browser's `EventSource` reads (greenhouse decisions/0188).
 *
 * Pure formatting, no I/O: each event becomes an SSE record carrying its `id` (so the client resumes with
 * `Last-Event-ID`), its `event` name, and its `data` JSON. A `retry` hint on the leading comment keeps the
 * reconnect cadence honest about this being short-poll SSE — the client reconnects and receives whatever
 * appeared since its last id. Continuous single-connection streaming is the deferred arc (a StreamedResponse
 * primitive the runtime does not yet have, plus a real hub — milpa/mercure).
 */
final class SseFormatter
{
    /** Milliseconds a browser waits before reconnecting after a feed response closes. */
    public const RETRY_MS = 2000;

    /**
     * Render the given id/event pairs as one `text/event-stream` body (leading keep-alive + retry hint).
     *
     * @param list<array{id: int, event: ShellEvent}> $events
     */
    public function format(array $events): string
    {
        // A leading comment keeps the stream valid even with zero events, and carries the retry hint.
        $body = ': keep-alive' . "\n" . 'retry: ' . self::RETRY_MS . "\n\n";

        foreach ($events as $entry) {
            $body .= 'id: ' . $entry['id'] . "\n"
                . 'event: ' . $entry['event']->type . "\n"
                . 'data: ' . $entry['event']->toJson() . "\n\n";
        }

        return $body;
    }
}
