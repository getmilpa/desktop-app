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

use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The milpa/live transport, same-origin under the Desktop (greenhouse decisions/0189, evidence/0490).
 *
 * `POST /desktop/live` verifies a signed state envelope + CSRF and runs a component interaction; the two
 * asset routes serve the client runtime and Alpine straight from the milpa/live-web package, so the Desktop
 * needs no build step.
 *
 * The page session the CSRF token is bound to comes from the REQUEST BODY (greenhouse decisions/0211): the
 * shell issues it with the boot (`LiveBoot::issue()`), the runtime echoes it as `sessionId` on every
 * action, and this adapter fills {@see LiveHttpRequest::$sessionId} from there. It is no longer read from
 * the `milpa_live_sid` cookie — a cookie another page set is not this page's session, and the shell no
 * longer sets one.
 */
final class LiveController
{
    public function __construct(private readonly LiveEndpoint $endpoint)
    {
    }

    /** Handle a component interaction: {action, state, payload} in, re-rendered HTML + new envelope out. */
    public function live(ServerRequestInterface $request): ResponseInterface
    {
        $decoded = json_decode((string) $request->getBody(), true);
        $body = \is_array($decoded) ? $decoded : [];

        $response = $this->endpoint->handle(new LiveHttpRequest(
            method: $request->getMethod(),
            action: \is_string($body['action'] ?? null) ? $body['action'] : '',
            stateEnvelope: \is_string($body['state'] ?? null) ? $body['state'] : '',
            payload: \is_array($body['payload'] ?? null) ? $body['payload'] : [],
            // The page session the boot issued and the runtime echoes — never a cookie (decisions/0211).
            sessionId: \is_string($body['sessionId'] ?? null) ? $body['sessionId'] : '',
            // The client runtime sends the CSRF token in the body; a header is accepted as a fallback.
            csrfToken: \is_string($body['csrfToken'] ?? null) && $body['csrfToken'] !== '' ? $body['csrfToken'] : $request->getHeaderLine('X-CSRF-Token'),
        ));

        return new Response(
            $response->status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            (string) json_encode($response->body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }

    /** Serve the milpa/live client runtime (the local Alpine factories) from the package, as-is. */
    public function client(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serveAsset('milpa-live.js');
    }

    /** Serve the milpa/live REMOTE runtime (server-driven factories: remote fields, autocomplete) from the package. */
    public function clientRemote(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serveAsset('milpa-live-remote.js');
    }

    /** Serve the vendored Alpine build from the package, as-is. */
    public function alpine(ServerRequestInterface $request): ResponseInterface
    {
        return $this->serveAsset('vendor/alpine.min.js');
    }

    private function serveAsset(string $relative): ResponseInterface
    {
        $root = \dirname((string) (new \ReflectionClass(LiveEndpoint::class))->getFileName(), 3);
        $path = $root . '/resources/' . $relative;
        $js = is_file($path) ? (string) file_get_contents($path) : '';
        if ($js === '') {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'not found');
        }

        return new Response(
            200,
            ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=3600'],
            $js,
        );
    }
}
