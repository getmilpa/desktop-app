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

use Milpa\DesktopApp\Http\RequestPrincipal;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Milpa\Live\ValueObjects\SecurityPrincipal;
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
 *
 * ── THE SHELL KNEW WHO YOU WERE AND THIS FORGOT ────────────────────────────────────────────────────
 *
 * {@see LiveEndpoint::handle()} takes a principal as its second argument, and this adapter used to pass
 * none — while `ShellController` read one from the same request to paint the chrome. So the Desktop knew
 * who you were when it PAINTED and forgot when you ACTED.
 *
 * MEASURED, and it is not the hole it first looked like. `ContractInteractionAuthorizer` has two checks and
 * they broke in opposite directions with a `null` caller:
 *
 *   - OWNERSHIP was stuck on DENY. State whose meta names an owner is refused unless the caller matches —
 *     and the caller was always null, so it was refused to EVERYONE, the owner included. That is why no
 *     component here mounts `meta['principal']`: per-principal state could not work, so nobody wrote any.
 *   - SCOPE was skipped, guarded by `if ($principal !== null && …)`. So no identity was more permissive
 *     than an unprivileged one — a default that fails open, and it stays that way under a loopback gate,
 *     where the door is the authority and there is no identity to carry.
 *
 * What it did NOT cost, said so nobody inherits a scare: no component handler in this package writes
 * anything. Every `handle()` projects, and the writes live on their own routes behind the same middleware
 * — `desktop-settings::save` only records that the door answered. So this makes per-principal component
 * state POSSIBLE, and turns the scope check on wherever a passkey gate supplies an identity.
 *
 * The principal is whatever the gate in front of the route left on the request, exactly as the shell reads
 * it. Under a loopback gate there is none and behaviour is unchanged: the door is the authority there, and
 * this adapter does not invent one it was not given.
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

        $response = $this->endpoint->handle(
            new LiveHttpRequest(
                method: $request->getMethod(),
                action: \is_string($body['action'] ?? null) ? $body['action'] : '',
                stateEnvelope: \is_string($body['state'] ?? null) ? $body['state'] : '',
                payload: \is_array($body['payload'] ?? null) ? $body['payload'] : [],
                // The page session the boot issued and the runtime echoes — never a cookie (decisions/0211).
                sessionId: \is_string($body['sessionId'] ?? null) ? $body['sessionId'] : '',
                // The client runtime sends the CSRF token in the body; a header is accepted as a fallback.
                csrfToken: \is_string($body['csrfToken'] ?? null) && $body['csrfToken'] !== '' ? $body['csrfToken'] : $request->getHeaderLine('X-CSRF-Token'),
            ),
            self::principal($request),
        );

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

    /**
     * Whoever the gate in front of this route left on the request — never invented here.
     *
     * The scopes mirror what the admin grants a signed-in principal: a human who passed this app's own
     * door drives this app's own components. Narrowing them is a decision about which surfaces a passkey
     * may touch, and it needs its own acta; what this closes is the hole where NO identity travelled at
     * all, and absence read as more authority than an unprivileged presence.
     */
    private static function principal(ServerRequestInterface $request): ?SecurityPrincipal
    {
        $id = RequestPrincipal::of($request);

        return $id === null ? null : new SecurityPrincipal($id, ['milpa:*']);
    }
}
