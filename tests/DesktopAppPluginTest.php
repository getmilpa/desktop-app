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
use Milpa\Http\HttpMethod;
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
    public function testItMountsTheShellRoute(): void
    {
        $plugin = new DesktopAppPlugin(new DIContainer());

        $routes = $plugin->routes();
        self::assertCount(1, $routes);
        $route = $routes[0];
        self::assertInstanceOf(Route::class, $route);
        self::assertSame('/desktop', $route->path);
        self::assertContains(HttpMethod::GET, $route->methods);
        self::assertNotNull($route->handler);
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

        self::assertCount(1, $plugin->routes(), 'the route is the whole effect');
    }
}
