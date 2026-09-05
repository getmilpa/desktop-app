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

use Milpa\Container\DIContainer;
use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\Tests\Fixtures\PasskeyGateStub;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Execution, not text (greenhouse D³): the unit tests call {@see DesktopAppPlugin::routes()} directly,
 * which proves the shape but NOT that the real runtime can boot the plugin and serve it. A Milpa app
 * boots plugins by class-string through {@see Kernel::boot()}, which requires `#[PluginMetadata]` and
 * mounts every booted `RouteProviderInterface`'s routes into the router the front controller dispatches
 * through. This test boots that real path end to end — the only witness that installing the plugin
 * actually serves `/desktop`, and, since the Desktop stands behind the same door as the admin
 * (greenhouse decisions/0209), the only witness that the door actually closes on the LAN.
 */
final class RuntimeBootTest extends TestCase
{
    public function testTheRuntimeBootsThePluginAndServesTheShellOverHttp(): void
    {
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class],
        ]);

        // The plugin was actually booted by the runtime (not merely instantiated by the test).
        self::assertContains('DesktopApp', $kernel->bootedPluginNames());

        $response = self::dispatch($kernel, 'GET', '/desktop', '127.0.0.1');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('Milpa Desktop', $body);
        // Same origin as the doors it must reach — the whole point of serving over HTTP (0188).
        self::assertStringContainsString('/webauthn/enroll', $body);
        self::assertStringContainsString('/webauthn/intent', $body);
        // The topbar names the gate in effect: the default, loopback-only.
        self::assertStringContainsString('data-gate="loopback"', $body);
        self::assertStringNotContainsString('data-principal=', $body, 'no gate authenticated anyone: no principal chip');
    }

    public function testWithoutThePluginTheShellRouteIsNotServed(): void
    {
        // Positive control's negative: the same runtime, the same request, but the plugin NOT installed.
        // If /desktop answered here, the route would be coming from somewhere other than this plugin.
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [],
        ]);

        $response = self::dispatch($kernel, 'GET', '/desktop', '127.0.0.1');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTheDefaultDoorRefusesTheLanAndTheAssetsStayPublic(): void
    {
        // The door, through the real pipeline (greenhouse decisions/0209): the router resolves the gate the
        // plugin registered, and a LAN address is refused — a page for a browser, JSON for the shell's calls.
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [DesktopAppPlugin::class]]);

        $page = self::dispatch($kernel, 'GET', '/desktop', '203.0.113.9', ['Accept' => 'text/html']);
        self::assertSame(403, $page->getStatusCode());
        self::assertStringContainsString('text/html', $page->getHeaderLine('Content-Type'));
        self::assertStringContainsString('desktop.middleware', (string) $page->getBody());

        $call = self::dispatch($kernel, 'POST', '/desktop/settings', '203.0.113.9', ['Content-Type' => 'application/json']);
        self::assertSame(403, $call->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'loopback_only'], json_decode((string) $call->getBody(), true));

        self::assertSame(403, self::dispatch($kernel, 'GET', '/desktop/export', '203.0.113.9')->getStatusCode(), 'the export is gated');
        self::assertSame(403, self::dispatch($kernel, 'GET', '/desktop/data.json', '203.0.113.9')->getStatusCode());

        // The assets are public package files: a JSON 401/403 to a <link> or <script> would break the page silently.
        foreach (['/desktop/assets/tokens.css', '/desktop/assets/bundle.css', '/desktop/assets/milpa-live.js', '/desktop/assets/milpa-live-remote.js', '/desktop/assets/alpine.min.js'] as $asset) {
            self::assertSame(200, self::dispatch($kernel, 'GET', $asset, '203.0.113.9')->getStatusCode(), $asset . ' is served to anyone');
        }

        // No address at all fails closed.
        self::assertSame(403, self::dispatch($kernel, 'GET', '/desktop', '')->getStatusCode());
    }

    public function testADeclaredEmptyListOpensTheDoorOnPurpose(): void
    {
        // The positive control of the refusal above: the same LAN address, the door declared open.
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class],
            'config' => ['desktop' => ['middleware' => []]],
        ]);

        $response = self::dispatch($kernel, 'GET', '/desktop', '203.0.113.9', ['Accept' => 'text/html']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-gate="open"', (string) $response->getBody());
    }

    public function testAMisdeclaredGateFallsToLoopbackOnlyInsteadOfDying(): void
    {
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class],
            'config' => ['desktop' => ['middleware' => ['Acme\\Nope']]],
        ]);

        self::assertSame(403, self::dispatch($kernel, 'GET', '/desktop', '203.0.113.9')->getStatusCode(), 'the LAN is refused, not served by a half-loaded gate');
        $local = self::dispatch($kernel, 'GET', '/desktop', '127.0.0.1');
        self::assertSame(200, $local->getStatusCode(), 'loopback still works — no 500 hiding the cause');
        self::assertStringContainsString('data-gate="fallback"', (string) $local->getBody());
        self::assertStringContainsString('mui-badge--warning', (string) $local->getBody(), 'the fallback wears the warning badge');
    }

    public function testAnAuthenticatingGateShowsWhoSignedIn(): void
    {
        // An app's own gate, registered in the container under its class name (as PasskeyPlugin does), named
        // under desktop.middleware: the router composes it in front of the shell, it authenticates, and the
        // topbar shows the actor it left on the request — read by duck-typing, no cookie, no session id.
        $container = new DIContainer();
        $container->registerService(PasskeyGateStub::class, new PasskeyGateStub());
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'container' => $container,
            'plugins' => [DesktopAppPlugin::class],
            'config' => ['desktop' => ['middleware' => [PasskeyGateStub::class]]],
        ]);

        $response = self::dispatch($kernel, 'GET', '/desktop', '203.0.113.9', ['Accept' => 'text/html']);

        self::assertSame(200, $response->getStatusCode(), 'identity replaces the address: the LAN is let in by the gate');
        $body = (string) $response->getBody();
        self::assertStringContainsString('signed in as passkey:stub', $body);
        self::assertStringContainsString('data-principal="passkey:stub"', $body);
        self::assertStringContainsString('data-gate="custom"', $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function dispatch(Kernel $kernel, string $method, string $path, string $address, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest($method, $path, $headers, null, '1.1', $address === '' ? [] : ['REMOTE_ADDR' => $address]);

        return (new RequestHandler($kernel, new Psr17Factory()))->handle($request);
    }
}
