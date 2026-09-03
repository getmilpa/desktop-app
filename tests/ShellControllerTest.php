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

namespace Milpa\DesktopApp\Tests;

use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\Eventing\EventDispatcher;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The shell is served over HTTP at a real origin (greenhouse decisions/0188): that is the move that
 * dissolves the file:// constraint, so the shell must reach the passkey doors it shares an origin with.
 * With a Mercure hub wired (0475) it also carries the live client.
 */
final class ShellControllerTest extends TestCase
{
    private function controller(?MercureConfig $mercure = null): ShellController
    {
        // A real dispatcher with no subscribers: dispatch is a no-op, so the shell renders its base.
        return new ShellController(new EventDispatcher(new NullLogger()), $mercure);
    }

    public function testItServesTheShellAsHtml(): void
    {
        $res = $this->controller()->shell(new ServerRequest('GET', '/desktop'));

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Milpa Desktop', (string) $res->getBody());
    }

    public function testTheShellReachesThePasskeyDoorsInThisOrigin(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        // Same-origin links: the whole point of serving the shell over HTTP.
        self::assertStringContainsString('/webauthn/enroll', $body);
        self::assertStringContainsString('/webauthn/intent', $body);
    }

    public function testWithoutAHubTheShellCarriesNoLiveClientOrCookie(): void
    {
        $res = $this->controller()->shell(new ServerRequest('GET', '/desktop'));

        self::assertSame('', $res->getHeaderLine('Set-Cookie'));
        self::assertStringNotContainsString('new EventSource(', (string) $res->getBody());
    }

    public function testWithAHubTheShellSetsTheCookieAndSubscribesOverEventSource(): void
    {
        $mercure = new MercureConfig(
            'http://hub/.well-known/mercure',
            'https://public.example/.well-known/mercure',
            'pub',
            'sub',
            'desktop/shell',
        );

        $res = $this->controller($mercure)->shell(new ServerRequest('GET', '/desktop'));
        $body = (string) $res->getBody();

        // The hub reads the subscriber JWT from this cookie.
        self::assertStringContainsString('mercureAuthorization=', $res->getHeaderLine('Set-Cookie'));
        // The client subscribes to the hub's PUBLIC url on the shell topic — no poll.
        self::assertStringContainsString('new EventSource(', $body);
        self::assertStringContainsString('https://public.example/.well-known/mercure?topic=desktop%2Fshell', $body);
    }
}
