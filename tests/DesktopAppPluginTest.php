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
use Milpa\DesktopApp\Admin\AdminGuest;
use Milpa\DesktopApp\Admin\AgentViewComponent;
use Milpa\DesktopApp\Admin\AgentViewRenderer;
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
    /**
     * The package files a page load pulls with `<link>` and `<script>`: never behind the door. Since the
     * declared views (greenhouse decisions/0211) that includes the per-component route family, whose path
     * carries the `{file}` placeholder.
     */
    private const ASSETS = [
        '/desktop/assets/tokens.css', '/desktop/assets/bundle.css', '/desktop/assets/c/{file}',
        '/desktop/assets/milpa-live.js', '/desktop/assets/milpa-live-remote.js', '/desktop/assets/alpine.min.js',
    ];

    public function testItMountsTheShellEventsAndAssetRoutes(): void
    {
        $plugin = new DesktopAppPlugin(new DIContainer());

        $routes = $plugin->routes();
        self::assertCount(14, $routes);
        foreach ($routes as $route) {
            self::assertInstanceOf(Route::class, $route);
            self::assertNotNull($route->handler);
        }
        $paths = array_map(static fn (Route $r): string => $r->path, $routes);
        self::assertSame(
            [
                '/desktop', '/desktop/events', '/desktop/assets/tokens.css', '/desktop/assets/bundle.css', '/desktop/assets/c/{file}',
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

    public function testItIsTheAdminsGuestAndDeclaresTheAgentSectionAsAView(): void
    {
        // The Desktop as the admin's guest (greenhouse decisions/0210, 0211): the plugin class carries the
        // admin's contract through the AdminGuest bridge — the admin, installed here, finds it by instanceof
        // — and declares ONE section, which since slice 3 declares a whole VIEW instead of one component.
        $plugin = new DesktopAppPlugin(new DIContainer());
        self::assertInstanceOf(AdminGuest::class, $plugin);
        self::assertInstanceOf(\Milpa\Admin\Section\AdminSectionProvider::class, $plugin);

        $sections = $plugin->adminSections();
        self::assertCount(1, $sections);
        $agent = $sections[0];
        self::assertInstanceOf(\Milpa\Admin\Section\AdminSection::class, $agent);
        self::assertSame('agent', $agent->id);
        self::assertSame('Agent', $agent->title, 'the title from the Desktop\'s catalog, English by default');
        self::assertSame(60, $agent->order, 'after the host\'s own 10..40 (greenhouse decisions/0210)');
        self::assertSame('agent', $agent->group);
        self::assertSame('◈', $agent->icon);

        // The narrow shape is gone: no `component`, no section props, no single definition/renderer pair.
        self::assertSame('', $agent->component);
        self::assertSame([], $agent->props);
        self::assertFalse($agent->isCustom());
        self::assertTrue($agent->hasView(), 'the section declares a view (greenhouse decisions/0211, slice 3)');

        $view = $agent->view;
        self::assertInstanceOf(\Milpa\Admin\Section\DeclaredView::class, $view);
        self::assertSame('<milpa:desktop-agent id="milpa-agent"/>', $view->markup, 'ONE root: the region contains and governs its own surfaces');
        self::assertSame(['open' => '/desktop', 'gate' => 'loopback', 'signin' => '/webauthn/signin'], $view->props['desktop-agent']);
        self::assertInstanceOf(AgentViewComponent::class, $view->definitions['desktop-agent']);
        self::assertInstanceOf(AgentViewRenderer::class, $view->renderers['desktop-agent']);
        self::assertFalse($view->seedsNothing(), 'the view seeds the signals its surfaces read');
        self::assertSame('chat', $view->signals['desktop.tab']);
        self::assertArrayHasKey('session.summary', $view->computed);

        // The props follow the declared door and locale — the gate the topbar chip says, the title in Spanish.
        $es = self::withConfig(['desktop' => ['locale' => 'es', 'middleware' => []]])->adminSections()[0];
        self::assertSame('Agente', $es->title);
        self::assertSame('open', $es->view?->props['desktop-agent']['gate']);
    }

    /**
     * A plugin that was never booted still declares a well-formed section — the registry it reads from the
     * container is not there, so the view carries the region's root and nothing else. The alternative is a
     * fatal inside milpa/admin's discovery, which would take the whole panel down with it.
     */
    public function testAnUnbootedPluginStillDeclaresTheSection(): void
    {
        $view = (new DesktopAppPlugin(new DIContainer()))->adminSections()[0]->view;

        self::assertInstanceOf(\Milpa\Admin\Section\DeclaredView::class, $view);
        self::assertSame(['desktop-agent'], $view->names(), 'no shell surfaces: nothing declared them');
    }

    public function testLifecycleHooksAreInert(): void
    {
        $plugin = new DesktopAppPlugin(new DIContainer());

        // Installing the plugin IS the activation; there is no persistent state to create or remove.
        $plugin->install();
        $plugin->uninstall();
        $plugin->enable();
        $plugin->disable();

        self::assertCount(14, $plugin->routes(), 'the shell, feed, assets (design system + per component), data, export, live + its assets, and the write endpoints');
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
