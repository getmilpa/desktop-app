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

namespace Milpa\DesktopApp;

use Milpa\Attributes\PluginMetadata;
use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * The «Desktop App» plugin: a Milpa app SERVES ITS OWN SHELL over HTTP (greenhouse decisions/0188).
 *
 * The Milpa Desktop is not an Electron app that DRIVES a separate Milpa; it is a Milpa that GAINS
 * desktop hands by installing this plugin. The backend lives in the SAME app. Installing the plugin
 * mounts a shell route; an Electron (or plain browser) host then loads that URL at a REAL origin
 * (`http://localhost:<port>/desktop`) instead of a `file://` renderer — and that single move
 * dissolves the constraint that blocked the passkey ceremony: WebAuthn refuses `file://` and an IP
 * is not a valid relying-party id, but the served shell shares its origin with the app's own
 * `/webauthn/*` doors, its live components and its consent gates. One channel, one origin.
 *
 * The first slice serves the shell and proves the seam; the reactive event bus (websockets /
 * milpa/mercure), the UI events other plugins hook, and the migration of the full renderer are the
 * arc named in 0188. Installing this plugin IS the activation — there is no config to fail closed on;
 * a Milpa without it simply has no desktop shell, which is the honest default.
 */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'DesktopApp',
    type: 'Web',
)]
final class DesktopAppPlugin implements PluginInterface, RouteProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** The container this plugin was booted with. */
    public function container(): DIContainerInterface
    {
        return $this->container;
    }

    /**
     * Register the shell controller so the router can resolve it, wired with the event dispatcher the
     * shell composes through. The kernel registers the dispatcher BEFORE any plugin boots, so it is
     * present here — the assert documents that invariant (and lets the container's own resolution be
     * the loud failure if the contract is ever broken).
     */
    public function boot(): void
    {
        $events = $this->container->get(MilpaEventDispatcherInterface::class);
        assert($events instanceof MilpaEventDispatcherInterface);

        $this->container->registerService(ShellController::class, new ShellController($events));
    }

    /** The shell route: the app's own UI, served over HTTP for a host to load at a real origin. */
    public function routes(): array
    {
        return [
            new Route(
                path: '/desktop',
                methods: HttpMethod::GET,
                name: 'desktop.shell',
                handler: new HandlerReference(ShellController::class, 'shell'),
            ),
        ];
    }

    /** No persistent state to create: the shell is served, not stored. */
    public function install(): void
    {
    }

    /** No persistent state to remove. */
    public function uninstall(): void
    {
    }

    /** Enabling is declaring it in config/plugins.php; serving the shell is the whole effect. */
    public function enable(): void
    {
    }

    /** Disabling removes it from config/plugins.php; nothing here to tear down. */
    public function disable(): void
    {
    }
}
