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

namespace Milpa\DesktopApp\Controllers;

use Milpa\DesktopApp\Live\ShellEventLog;
use Milpa\DesktopApp\Live\SseFormatter;
use Milpa\Runtime\Http\CallbackStream;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the shell's live event feed as a continuous `text/event-stream` (greenhouse decisions/0188, 0473).
 *
 * A browser opens `new EventSource('/desktop/events')` and HOLDS the connection: this returns a
 * {@see CallbackStream} body that the runtime's streaming emitter (evidence/0472) runs, writing the backlog
 * since the client's cursor and then tailing the shared log — flushing each new event as a plugin appends
 * it — for a bounded window before closing so `EventSource` reconnects with `Last-Event-ID`. This is the
 * real single-connection push the short-poll of 0471 stood in for; it depends on the app emitting through
 * `ResponseEmitter` (a plain buffered emit would serve nothing, by CallbackStream's design).
 *
 * The window and poll interval are bounded so a connection is recycled (proxies, sleep) rather than held
 * forever, and so the stream is testable with a zero window (backlog only, no sleep). A real hub that
 * replaces the file log and removes the poll is milpa/mercure — this feed is its output shape.
 */
final class EventsController
{
    public function __construct(
        private readonly ShellEventLog $log,
        private readonly SseFormatter $formatter,
        private readonly int $windowMs,
        private readonly int $pollMs,
    ) {
    }

    /** Open a live SSE stream from the client's cursor. */
    public function events(ServerRequestInterface $request): ResponseInterface
    {
        $cursor = $this->cursor($request);

        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ],
            new CallbackStream(fn (): null => $this->stream($cursor)),
        );
    }

    /**
     * Write the backlog, then tail the log for new events until the window closes.
     *
     * This only writes and flushes; defeating PHP/proxy output buffering is a deployment concern (the
     * `X-Accel-Buffering: no` header above, and `output_buffering` off for the streaming entry point) — kept
     * out of here so the loop stays pure enough to capture in a test.
     */
    private function stream(int $cursor): null
    {
        echo $this->formatter->preamble();
        flush();

        $deadline = microtime(true) + $this->windowMs / 1000;
        do {
            foreach ($this->log->since($cursor) as $entry) {
                echo $this->formatter->event($entry['id'], $entry['event']);
                $cursor = $entry['id'];
            }
            flush();

            if (microtime(true) >= $deadline) {
                break;
            }
            usleep($this->pollMs * 1000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /** The client's cursor: `Last-Event-ID` (set by EventSource on reconnect) wins, else `?since=`, else 0. */
    private function cursor(ServerRequestInterface $request): int
    {
        $lastEventId = $request->getHeaderLine('Last-Event-ID');
        if ($lastEventId !== '' && ctype_digit($lastEventId)) {
            return (int) $lastEventId;
        }

        $since = $request->getQueryParams()['since'] ?? null;
        if (is_string($since) && ctype_digit($since)) {
            return (int) $since;
        }

        return 0;
    }
}
