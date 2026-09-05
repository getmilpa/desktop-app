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
use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\Http\LoopbackOnlyMiddleware;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Tests\Fixtures\AllowAllMiddleware;
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Stack\StackProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The plugin mounts the shell route and registers its controller (greenhouse decisions/0188): a Milpa
 * app that installs this plugin serves its own shell over HTTP. It also declares the Mercure hub it
 * needs through the runtime's stack contract (greenhouse decisions/0201), and stands every non-asset
 * route behind the door the app declared — loopback-only by default (greenhouse decisions/0209).
 */
final class DesktopAppPluginTest extends TestCase
{
    /** The five package files a page load pulls with `<link>` and `<script>`: never behind the door. */
    private const ASSETS = [
        '/desktop/assets/tokens.css', '/desktop/assets/bundle.css',
        '/desktop/assets/milpa-live.js', '/desktop/assets/milpa-live-remote.js', '/desktop/assets/alpine.min.js',
    ];

    public function testItMountsTheShellEventsAndAssetRoutes(): void
    {
        $plugin = new DesktopAppPlugin(new DIContainer());

        $routes = $plugin->routes();
        self::assertCount(13, $routes);
        foreach ($routes as $route) {
            self::assertInstanceOf(Route::class, $route);
            self::assertNotNull($route->handler);
        }
        $paths = array_map(static fn (Route $r): string => $r->path, $routes);
        self::assertSame(
            [
                '/desktop', '/desktop/events', '/desktop/assets/tokens.css', '/desktop/assets/bundle.css',
                '/desktop/data.json', '/desktop/export', '/desktop/live', '/desktop/assets/milpa-live.js', '/desktop/assets/milpa-live-remote.js',
                '/desktop/assets/alpine.min.js', '/desktop/settings', '/desktop/sessions', '/desktop/work',
            ],
            $paths,
        );
    }

    public function testEveryRouteButTheAssetsCarriesTheDoorLoopbackOnlyByDefault(): void
    {
        $plugin = new DesktopAppPlugin(new DIContainer());

        self::assertSame([LoopbackOnlyMiddleware::class], $plugin->settings()->effectiveMiddleware());
        self::assertSame(['/desktop', '/desktop/events', '/desktop/data.json', '/desktop/export', '/desktop/live', '/desktop/settings', '/desktop/sessions', '/desktop/work'], self::gatedPaths($plugin->routes()));
        foreach ($plugin->routes() as $route) {
            $isAsset = \in_array($route->path, self::ASSETS, true);
            self::assertSame($isAsset ? [] : [LoopbackOnlyMiddleware::class], $route->middleware, $route->path);
        }
    }

    public function testTheDeclaredGateReachesEveryNonAssetRouteAndTheAssetsStayPublic(): void
    {
        $custom = self::withConfig(['desktop' => ['middleware' => [AllowAllMiddleware::class]]]);
        foreach ($custom->routes() as $route) {
            self::assertSame(\in_array($route->path, self::ASSETS, true) ? [] : [AllowAllMiddleware::class], $route->middleware, $route->path);
        }

        $open = self::withConfig(['desktop' => ['middleware' => []]]);
        foreach ($open->routes() as $route) {
            self::assertSame([], $route->middleware, $route->path . ' — a literally empty list opens the Desktop on purpose');
        }
        self::assertSame('open', $open->settings()->gateKind());

        $typo = self::withConfig(['desktop' => ['middleware' => [AllowAllMiddleware::class, 'Acme\\Nope']]]);
        self::assertSame([LoopbackOnlyMiddleware::class], $typo->routes()[0]->middleware, 'the whole stack falls to loopback-only — never the half that loads');
        self::assertSame(8, \count(self::gatedPaths($typo->routes())));
        self::assertSame('fallback', $typo->settings()->gateKind());
    }

    public function testBootRegistersTheShellControllerAndTheGate(): void
    {
        $container = new DIContainer();
        // The kernel registers the dispatcher before plugins boot; mirror that here.
        $container->registerService(MilpaEventDispatcherInterface::class, new EventDispatcher(new NullLogger()));
        $plugin = new DesktopAppPlugin($container);
        $plugin->boot();

        // The router resolves the handler's class from the container; after boot it is the shell controller.
        self::assertInstanceOf(ShellController::class, $container->get(ShellController::class));
        // And the gate under its class name, so the router's resolver can compose it in front of the routes.
        self::assertInstanceOf(LoopbackOnlyMiddleware::class, $container->get(LoopbackOnlyMiddleware::class));
    }

    public function testAnAppsOwnGateInstanceIsNotReplacedAndTheDeclaredLocaleReachesIt(): void
    {
        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, new EventDispatcher(new NullLogger()));
        $own = new LoopbackOnlyMiddleware(new Catalog('es'));
        $container->registerService(LoopbackOnlyMiddleware::class, $own);
        (new DesktopAppPlugin($container))->boot();
        self::assertSame($own, $container->get(LoopbackOnlyMiddleware::class), 'registered only when absent');

        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, new EventDispatcher(new NullLogger()));
        $container->registerService(Config::class, new Config(['desktop' => ['locale' => 'es']]));
        $plugin = new DesktopAppPlugin($container);
        $plugin->boot();
        self::assertSame('es', $plugin->settings()->locale);
        $gate = $container->get(LoopbackOnlyMiddleware::class);
        self::assertInstanceOf(LoopbackOnlyMiddleware::class, $gate);
        $refusal = $gate->process(
            new \Nyholm\Psr7\ServerRequest('GET', '/desktop', ['Accept' => 'text/html'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']),
            new class () implements \Psr\Http\Server\RequestHandlerInterface {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return new \Nyholm\Psr7\Response(200);
                }
            },
        );
        self::assertStringContainsString('Sólo loopback', (string) $refusal->getBody(), 'the gate speaks the declared locale');
    }

    public function testItDeclaresTheMercureHubItNeeds(): void
    {
        $plugin = new DesktopAppPlugin(new DIContainer());

        self::assertInstanceOf(StackProviderInterface::class, $plugin, 'the first real declarant of the stack contract (decisions/0201)');
        $services = $plugin->services();
        self::assertCount(1, $services, 'one service: the hub');
        self::assertSame('mercure', $services[0]->name);
        self::assertSame('dunglas/mercure', $services[0]->image);
        self::assertSame(3000, $services[0]->probePort(), 'no hub url configured: the default published port');
    }

    public function testTheDeclaredHubPublishesThePortTheAppPublishesTo(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config([
            'desktop' => ['mercure' => ['hub_url' => 'http://127.0.0.1:3010/.well-known/mercure']],
        ]));

        $services = (new DesktopAppPlugin($container))->services();

        self::assertSame(3010, $services[0]->probePort(), 'the declaration reads the same config the wiring does');
    }

    public function testItExposesItsContainer(): void
    {
        $container = new DIContainer();
        self::assertSame($container, (new DesktopAppPlugin($container))->container());
    }

    public function testLifecycleHooksAreInert(): void
    {
        $plugin = new DesktopAppPlugin(new DIContainer());

        // Installing the plugin IS the activation; there is no persistent state to create or remove.
        $plugin->install();
        $plugin->uninstall();
        $plugin->enable();
        $plugin->disable();

        self::assertCount(13, $plugin->routes(), 'the shell, feed, assets, data, export, live + its assets, and the write endpoints');
        $paths = array_map(static fn ($r): string => $r->path, $plugin->routes());
        self::assertContains('/desktop/export', $paths, 'the session export (autopsy/video material)');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function withConfig(array $config): DesktopAppPlugin
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config($config));

        return new DesktopAppPlugin($container);
    }

    /**
     * @param list<Route> $routes
     *
     * @return list<string>
     */
    private static function gatedPaths(array $routes): array
    {
        return array_values(array_map(
            static fn (Route $r): string => $r->path,
            array_filter($routes, static fn (Route $r): bool => $r->middleware !== []),
        ));
    }
}
