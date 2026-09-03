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
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The plugin mounts the shell route and registers its controller (greenhouse decisions/0188): a Milpa
 * app that installs this plugin serves its own shell over HTTP.
 */
final class DesktopAppPluginTest extends TestCase
{
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

    public function testBootRegistersTheShellController(): void
    {
        $container = new DIContainer();
        // The kernel registers the dispatcher before plugins boot; mirror that here.
        $container->registerService(MilpaEventDispatcherInterface::class, new EventDispatcher(new NullLogger()));
        $plugin = new DesktopAppPlugin($container);
        $plugin->boot();

        // The router resolves the handler's class from the container; after boot it is the shell controller.
        self::assertInstanceOf(ShellController::class, $container->get(ShellController::class));
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
}
