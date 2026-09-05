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

namespace Milpa\DesktopApp\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who the gate let in, read from the request — never from a cookie, never from a session store
 * (greenhouse decisions/0209).
 *
 * A gate that authenticates (app-runtime's `PasskeyGateMiddleware`, or anything built on `milpa/auth`'s
 * `AuthenticateMiddleware`) leaves its verdict on the request under the attribute `milpa.auth`: an
 * `AuthContext` — `isAuthenticated()`, and a public `actor` whose public `id` is the principal. The
 * Desktop takes no dependency on `milpa/auth`, so it reads that shape by duck-typing, and fails closed:
 * no attribute, a non-object, a context that does not say it is authenticated, an actor without a
 * non-empty string id — each is «nobody», and the topbar shows no chip. What comes back is the ACTOR's
 * id (`passkey:…`), never a session id: the session is the gate's, and the Desktop never sees it.
 *
 * The reader never calls what it cannot: a member answered by a method is read only when that method
 * is public and needs no argument, and a call that throws reads as nobody — a foreign context is read,
 * never trusted to behave.
 *
 * A conscious duplication of milpa/admin's `RequestPrincipal`, for the same reason its gate is: the
 * Desktop names no dependency on the admin, and the neutral home would be milpa/app-runtime.
 */
final class RequestPrincipal
{
    /** The request attribute `AuthenticateMiddleware` (milpa/auth) and `PasskeyGateMiddleware` (app-runtime) leave the `AuthContext` under. */
    public const ATTRIBUTE = 'milpa.auth';

    /** The authenticated actor's id, or null when the request carries no authenticated context. */
    public static function of(ServerRequestInterface $request): ?string
    {
        $context = $request->getAttribute(self::ATTRIBUTE);
        if (!\is_object($context) || self::call($context, 'isAuthenticated') !== true) {
            return null;
        }
        $actor = self::member($context, 'actor');
        if (!\is_object($actor)) {
            return null;
        }
        $id = self::member($actor, 'id');

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /** One member of a foreign object: its public property of that name, else its method of that name {@see self::call()}ed, else null. */
    private static function member(object $subject, string $name): mixed
    {
        $public = get_object_vars($subject);

        return \array_key_exists($name, $public) ? $public[$name] : self::call($subject, $name);
    }

    /**
     * A method of a foreign object called — only when it exists, is public and requires no argument: a
     * protected one, or one that needs a parameter, is not a member the Desktop can read, so it is never
     * called. Null when it cannot be called, and null when the call throws.
     */
    private static function call(object $subject, string $name): mixed
    {
        if (!method_exists($subject, $name)) {
            return null;
        }

        try {
            $method = new \ReflectionMethod($subject, $name);
            if (!$method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            return $method->invoke($subject);
        } catch (\Throwable) {
            return null;
        }
    }
}
