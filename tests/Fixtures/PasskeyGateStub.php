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

use Milpa\DesktopApp\Http\RequestPrincipal;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A gate that authenticates: it leaves an object shaped like milpa/auth's `AuthContext` under the
 * `milpa.auth` attribute — what app-runtime's `PasskeyGateMiddleware` does after a passkey session —
 * without either package.
 */
final class PasskeyGateStub implements MiddlewareInterface
{
    public const PRINCIPAL = 'passkey:stub';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute(RequestPrincipal::ATTRIBUTE, self::context(self::PRINCIPAL)));
    }

    /**
     * An object shaped like milpa/auth's `AuthContext` for an authenticated actor — `isAuthenticated()`
     * true and a public `actor` with a public string `id` — without the package.
     */
    public static function context(string $id): object
    {
        return new class ($id) {
            public object $actor;

            public function __construct(string $id)
            {
                $this->actor = new class ($id) {
                    public function __construct(public string $id)
                    {
                    }
                };
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
