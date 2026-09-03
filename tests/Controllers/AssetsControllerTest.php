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

namespace Milpa\DesktopApp\Tests\Controllers;

use Milpa\DesktopApp\Controllers\AssetsController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Serves the vendored Milpa design system as cached CSS (greenhouse decisions/0479): the tokens and the
 * `mui-*` component bundle the dashboard is built from.
 */
final class AssetsControllerTest extends TestCase
{
    public function testItServesTheTokensAsCachedCss(): void
    {
        $res = (new AssetsController())->tokens(new ServerRequest('GET', '/desktop/assets/tokens.css'));

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('immutable', $res->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('--tierra-950', (string) $res->getBody(), 'the design tokens');
    }

    public function testItServesTheComponentBundle(): void
    {
        $res = (new AssetsController())->bundle(new ServerRequest('GET', '/desktop/assets/bundle.css'));

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('.mui-', (string) $res->getBody(), 'the mui-* components');
    }
}
