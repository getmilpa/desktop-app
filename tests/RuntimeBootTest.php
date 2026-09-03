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

use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Execution, not text (greenhouse D³): the unit tests call {@see DesktopAppPlugin::routes()} directly,
 * which proves the shape but NOT that the real runtime can boot the plugin and serve it. A Milpa app
 * boots plugins by class-string through {@see Kernel::boot()}, which requires `#[PluginMetadata]` and
 * mounts every booted `RouteProviderInterface`'s routes into the router the front controller dispatches
 * through. This test boots that real path end to end — the only witness that installing the plugin
 * actually serves `/desktop`.
 */
final class RuntimeBootTest extends TestCase
{
    public function testTheRuntimeBootsThePluginAndServesTheShellOverHttp(): void
    {
        $psr17 = new Psr17Factory();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class],
        ]);

        // The plugin was actually booted by the runtime (not merely instantiated by the test).
        self::assertContains('DesktopApp', $kernel->bootedPluginNames());

        $response = (new RequestHandler($kernel, $psr17))->handle(new ServerRequest('GET', '/desktop'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('Milpa Desktop', $body);
        // Same origin as the doors it must reach — the whole point of serving over HTTP (0188).
        self::assertStringContainsString('/webauthn/enroll', $body);
        self::assertStringContainsString('/webauthn/intent', $body);
    }

    public function testWithoutThePluginTheShellRouteIsNotServed(): void
    {
        // Positive control's negative: the same runtime, the same request, but the plugin NOT installed.
        // If /desktop answered here, the route would be coming from somewhere other than this plugin.
        $psr17 = new Psr17Factory();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [],
        ]);

        $response = (new RequestHandler($kernel, $psr17))->handle(new ServerRequest('GET', '/desktop'));

        self::assertSame(404, $response->getStatusCode());
    }
}
