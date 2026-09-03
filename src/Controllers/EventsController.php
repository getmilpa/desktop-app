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
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the shell's live event feed as `text/event-stream` (greenhouse decisions/0188).
 *
 * A browser opens `new EventSource('/desktop/events')`; this returns every shell event that appeared
 * since the client's cursor and closes, and `EventSource` reconnects — carrying `Last-Event-ID`, which is
 * read back as the cursor so nothing is missed and nothing is repeated. `?since=` is the same cursor for
 * a plain client (curl, a test). Same origin as the shell it feeds — the whole point of 0188.
 *
 * This rides the runtime's buffered response model on purpose: each feed response is short. Continuous
 * single-connection push (and a hub that scales past a file) is the deferred arc — milpa/mercure.
 */
final class EventsController
{
    public function __construct(
        private readonly ShellEventLog $log,
        private readonly SseFormatter $formatter,
    ) {
    }

    /** Serve the events that appeared after the client's cursor, in SSE wire format. */
    public function events(ServerRequestInterface $request): ResponseInterface
    {
        $cursor = $this->cursor($request);

        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store',
                // Tell reverse proxies not to buffer the feed (nginx, in particular).
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ],
            $this->formatter->format($this->log->since($cursor)),
        );
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
