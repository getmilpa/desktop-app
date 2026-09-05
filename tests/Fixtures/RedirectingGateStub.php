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

namespace Milpa\DesktopApp\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A gate with no session to find: it sends every request to the sign-in door with `next` set to where it was
 * going — path AND query, the way app-runtime's `PasskeyGateMiddleware::signinUrl()` builds it — without the
 * package. What the suite measures is that the request target reaches `next` intact through the real pipeline.
 */
final class RedirectingGateStub implements MiddlewareInterface
{
    public const SIGNIN = '/webauthn/signin';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $next = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());

        return new Response(302, ['Location' => self::SIGNIN . '?next=' . rawurlencode($next)]);
    }
}
