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
use Milpa\DesktopApp\Controllers\AssetsController;
use Milpa\DesktopApp\Controllers\DataController;
use Milpa\DesktopApp\Controllers\EventsController;
use Milpa\DesktopApp\Controllers\LiveController;
use Milpa\DesktopApp\Controllers\MutationController;
use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\Live\ComposerField;
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\Data\DesktopStore;
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

        $log = new ShellEventLog($this->logPath());

        $store = new DesktopStore($this->sessionsPath(), $this->settingsPath());
        $this->container->registerService(DesktopStore::class, $store);
        $this->container->registerService(MutationController::class, new MutationController($store));

        $data = new DesktopData($this->container, $log, $this->sessionsPath(), $store);
        $this->container->registerService(DesktopData::class, $data);
        $this->container->registerService(DataController::class, new DataController($data));

        $mercure = $this->mercure();
        // milpa/live — the framework's official UI system — powers the composer field as a real component
        // (greenhouse decisions/0189). The registry is extensible: an agent or a human registers new
        // primitives the same way ComposerField registers the textarea.
        $composerField = new ComposerField($this->liveSecret('signing'), $this->liveSecret('csrf'), $events);
        $this->container->registerService(ComposerField::class, $composerField);
        $this->container->registerService(LiveController::class, new LiveController($composerField->endpoint()));

        // The sidebar is the shell's first pure-Milpa-Components surface (greenhouse decisions/0189): a
        // declared component with a signed envelope, lifecycle events and a signal-driven active nav.
        $sidebar = new \Milpa\DesktopApp\Live\Sidebar($this->liveSecret('signing'), $data, $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Sidebar::class, $sidebar);

        // The topbar is the shell's second pure-Milpa-Components surface (greenhouse decisions/0189): a
        // projection surface reading shared signals, with a signed envelope and lifecycle events.
        $topbar = new \Milpa\DesktopApp\Live\Topbar($this->liveSecret('signing'), $data, $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Topbar::class, $topbar);

        // The main tablist is the shell's third pure-Milpa-Components surface (greenhouse decisions/0189): the
        // tablist declares the shared `desktop.tab` signal; the panes and composer dock project it.
        $tabs = new \Milpa\DesktopApp\Live\Tabs($this->liveSecret('signing'), $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Tabs::class, $tabs);

        // The Work board is the shell's fourth pure-Milpa-Components surface (greenhouse decisions/0189): a
        // projection component with a signed envelope and lifecycle events; drag-drop persists via /desktop/work.
        $workBoard = new \Milpa\DesktopApp\Live\WorkBoard($this->liveSecret('signing'), $data, $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\WorkBoard::class, $workBoard);

        $this->container->registerService(ShellController::class, new ShellController($events, $mercure, $data, $composerField, $sidebar, $topbar, $tabs, $workBoard));

        $this->container->registerService(AssetsController::class, new AssetsController());

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
            new Route(
                path: '/desktop/assets/tokens.css',
                methods: HttpMethod::GET,
                name: 'desktop.assets.tokens',
                handler: new HandlerReference(AssetsController::class, 'tokens'),
            ),
            new Route(
                path: '/desktop/assets/bundle.css',
                methods: HttpMethod::GET,
                name: 'desktop.assets.bundle',
                handler: new HandlerReference(AssetsController::class, 'bundle'),
            ),
            new Route(
                path: '/desktop/data.json',
                methods: HttpMethod::GET,
                name: 'desktop.data',
                handler: new HandlerReference(DataController::class, 'data'),
            ),
            new Route(
                path: '/desktop/export',
                methods: HttpMethod::GET,
                name: 'desktop.export',
                handler: new HandlerReference(DataController::class, 'export'),
            ),
            new Route(
                path: '/desktop/live',
                methods: HttpMethod::POST,
                name: 'desktop.live',
                handler: new HandlerReference(LiveController::class, 'live'),
            ),
            new Route(
                path: '/desktop/assets/milpa-live.js',
                methods: HttpMethod::GET,
                name: 'desktop.assets.live',
                handler: new HandlerReference(LiveController::class, 'client'),
            ),
            new Route(
                path: '/desktop/assets/milpa-live-remote.js',
                methods: HttpMethod::GET,
                name: 'desktop.assets.live.remote',
                handler: new HandlerReference(LiveController::class, 'clientRemote'),
            ),
            new Route(
                path: '/desktop/assets/alpine.min.js',
                methods: HttpMethod::GET,
                name: 'desktop.assets.alpine',
                handler: new HandlerReference(LiveController::class, 'alpine'),
            ),
            new Route(
                path: '/desktop/settings',
                methods: HttpMethod::POST,
                name: 'desktop.settings.save',
                handler: new HandlerReference(MutationController::class, 'saveSettings'),
            ),
            new Route(
                path: '/desktop/sessions',
                methods: HttpMethod::POST,
                name: 'desktop.sessions.create',
                handler: new HandlerReference(MutationController::class, 'createSession'),
            ),
            new Route(
                path: '/desktop/work',
                methods: HttpMethod::POST,
                name: 'desktop.work.move',
                handler: new HandlerReference(MutationController::class, 'moveWork'),
            ),
        ];
    }

    /** The Mercure hub wiring, when the app configured `desktop.mercure.*`; null otherwise (log-only). */
    private function mercure(): ?MercureConfig
    {
        $config = $this->container->get(Config::class);

        return $config instanceof Config ? MercureConfig::fromConfig($config) : null;
    }

    /** Where persisted Desktop settings live: `desktop.settings.path` in config, else `.milpa/desktop-settings.json`. */
    private function settingsPath(): string
    {
        $config = $this->container->get(Config::class);
        $configured = $config instanceof Config ? $config->get('desktop.settings.path') : null;

        return is_string($configured) && $configured !== '' ? $configured : getcwd() . '/.milpa/desktop-settings.json';
    }

    /** Where the app's session store lives: `desktop.sessions.path` in config, else `.milpa/sessions/`. */
    private function sessionsPath(): string
    {
        $config = $this->container->get(Config::class);
        $configured = $config instanceof Config ? $config->get('desktop.sessions.path') : null;

        return is_string($configured) && $configured !== '' ? $configured : getcwd() . '/.milpa/sessions';
    }

    /** Where the shared event log lives: `desktop.events.log` in config, else a per-app temp file. */
    /**
     * The HMAC secret for the live component's signed state / CSRF: `desktop.live.<kind>_secret` in config,
     * else a stable per-install value derived from this package's path. Set it in config for a real deployment.
     */
    private function liveSecret(string $kind): string
    {
        $config = $this->container->get(Config::class);
        $configured = $config instanceof Config ? $config->get('desktop.live.' . $kind . '_secret') : null;

        return is_string($configured) && $configured !== '' ? $configured : hash('sha256', __DIR__ . '|milpa-live|' . $kind);
    }

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
