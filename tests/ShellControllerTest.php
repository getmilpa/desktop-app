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
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The shell is served over HTTP at a real origin (greenhouse decisions/0188): that is the move that
 * dissolves the file:// constraint, so the shell must reach the passkey doors it shares an origin with.
 */
final class ShellControllerTest extends TestCase
{
    public function testItServesTheShellAsHtml(): void
    {
        $res = (new ShellController())->shell(new ServerRequest('GET', '/desktop'));

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Milpa Desktop', (string) $res->getBody());
    }

    public function testTheShellReachesThePasskeyDoorsInThisOrigin(): void
    {
        $body = (string) (new ShellController())->shell(new ServerRequest('GET', '/desktop'))->getBody();

        // Same-origin links: the whole point of serving the shell over HTTP.
        self::assertStringContainsString('/webauthn/enroll', $body);
        self::assertStringContainsString('/webauthn/intent', $body);
    }
}
