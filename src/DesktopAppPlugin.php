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
use Milpa\DesktopApp\Controllers\EventsController;
use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\DesktopApp\Live\MercurePublisher;
use Milpa\DesktopApp\Live\ShellChangeRecorder;
use Milpa\DesktopApp\Live\ShellEvent;
use Milpa\DesktopApp\Live\ShellEventLog;
use Milpa\DesktopApp\Live\SseFormatter;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Config;
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
    /** A plugin dispatches this (with a {@see ShellEvent} in `payload['shellEvent']`) to push a live update. */
    public const CHANGED_EVENT = 'desktop.shell.changed';

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** The container this plugin was booted with. */
    public function container(): DIContainerInterface
    {
        return $this->container;
    }

    /**
     * Wire the shell controller (composed through the event dispatcher) and the live event feed (backed by
     * the shared event log). The kernel registers the dispatcher and the Config bag BEFORE any plugin
     * boots, so both are present here — the assert documents that invariant. Subscribing to
     * {@see CHANGED_EVENT} is how any plugin's live update reaches connected clients: the dispatched
     * {@see ShellEvent} is appended to the log the SSE feed reads.
     */
    public function boot(): void
    {
        $events = $this->container->get(MilpaEventDispatcherInterface::class);
        assert($events instanceof MilpaEventDispatcherInterface);

        $mercure = $this->mercure();
        $this->container->registerService(ShellController::class, new ShellController($events, $mercure));

        $log = new ShellEventLog($this->logPath());
        [$windowMs, $pollMs] = $this->feedTiming();
        $this->container->registerService(EventsController::class, new EventsController($log, new SseFormatter(), $windowMs, $pollMs));

        $publisher = $mercure !== null ? new MercurePublisher($mercure->service(), $mercure->topic) : null;
        $recorder = new ShellChangeRecorder($log, $publisher);

        $events->subscribe(self::CHANGED_EVENT, static function (string $eventName, array $payload) use ($recorder): void {
            $shellEvent = $payload['shellEvent'] ?? null;
            if ($shellEvent instanceof ShellEvent) {
                $recorder->record($shellEvent);
            }
        });
    }

    /** The shell and its live event feed — both served over HTTP for a host to load at a real origin. */
    public function routes(): array
    {
        return [
            new Route(
                path: '/desktop',
                methods: HttpMethod::GET,
                name: 'desktop.shell',
                handler: new HandlerReference(ShellController::class, 'shell'),
            ),
            new Route(
                path: '/desktop/events',
                methods: HttpMethod::GET,
                name: 'desktop.events',
                handler: new HandlerReference(EventsController::class, 'events'),
            ),
        ];
    }

    /** The Mercure hub wiring, when the app configured `desktop.mercure.*`; null otherwise (log-only). */
    private function mercure(): ?MercureConfig
    {
        $config = $this->container->get(Config::class);

        return $config instanceof Config ? MercureConfig::fromConfig($config) : null;
    }

    /** Where the shared event log lives: `desktop.events.log` in config, else a per-app temp file. */
    private function logPath(): string
    {
        $config = $this->container->get(Config::class);
        $configured = $config instanceof Config ? $config->get('desktop.events.log') : null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : sys_get_temp_dir() . '/milpa-desktop-shell-events.log';
    }

    /**
     * The live feed's connection window and poll interval, both in milliseconds.
     *
     * @return array{0: int, 1: int}
     */
    private function feedTiming(): array
    {
        $config = $this->container->get(Config::class);
        $windowMs = $config instanceof Config ? $config->get('desktop.events.window_ms', 25000) : 25000;
        $pollMs = $config instanceof Config ? $config->get('desktop.events.poll_ms', 1000) : 1000;

        return [is_int($windowMs) ? $windowMs : 25000, is_int($pollMs) ? $pollMs : 1000];
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
