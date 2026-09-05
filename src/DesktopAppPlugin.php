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
use Milpa\DesktopApp\Http\LoopbackOnlyMiddleware;
use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\DesktopApp\Live\MercurePublisher;
use Milpa\DesktopApp\Live\MercureServiceDeclaration;
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
use Milpa\Runtime\Stack\ServiceDeclaration;
use Milpa\Runtime\Stack\StackProviderInterface;

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
 *
 * It is also the first real declarant of the runtime's stack contract (greenhouse decisions/0201): it
 * DECLARES the Mercure hub it needs — as data, through {@see StackProviderInterface} — so whoever operates
 * the host can list it, probe it and project a compose fragment without this plugin knowing who asks.
 *
 * The Desktop stands behind the same door as the admin (greenhouse decisions/0209): every shell route carries
 * the middleware the app declared under `desktop.middleware` — judged by {@see DesktopSettings}, loopback-only
 * by default, `[]` open on purpose, anything misdeclared falling to loopback-only — except the assets, which
 * stay public package files: a JSON 401 to a `<link>` or `<script>` would break the page silently.
 */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'DesktopApp',
    type: 'Web',
)]
final class DesktopAppPlugin implements PluginInterface, RouteProviderInterface, StackProviderInterface
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

        // The door (greenhouse decisions/0209): the declared gate, judged once; the catalog in the declared
        // locale; and the strict gate registered under its class name so the router can resolve it from the
        // container — unless the app registered its own instance first. «Absent» is asked of the underlying
        // PSR-11 container: the wrapper's has() also says yes to anything it could auto-wire, and an auto-wired
        // gate would speak English whatever the app declared.
        $settings = $this->settings();
        $catalog = $settings->catalog();
        if (!$this->container->getContainer()->has(LoopbackOnlyMiddleware::class)) {
            $this->container->registerService(LoopbackOnlyMiddleware::class, new LoopbackOnlyMiddleware($catalog));
        }

        $log = new ShellEventLog($this->logPath());

        $store = new DesktopStore($this->sessionsPath(), $this->settingsPath());
        $this->container->registerService(DesktopStore::class, $store);
        $this->container->registerService(MutationController::class, new MutationController($store));

        $data = new DesktopData($this->container, $log, $this->sessionsPath(), $store);
        $this->container->registerService(DesktopData::class, $data);
        $this->container->registerService(DataController::class, new DataController($data));

        $mercure = $this->mercure();
        // Expose the Mercure hub to the runtime under milpa/mercure's own name, so a governed agent turn run
        // over the HTTP surface streams its session.* events — reasoning included — to the SAME hub the shell
        // reads (greenhouse decisions/0190). AgentOperations' broadcaster() finds it here and BroadcastingEventStore
        // publishes to `milpa/sessions/<id>`; the shell subscribes to that topic. A Desktop with no hub configured
        // registers nothing and the turn simply does not stream, which is the honest default.
        if ($mercure !== null) {
            $this->container->registerService(\Milpa\Mercure\MercureService::class, $mercure->service());
        }
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
        // projection surface reading shared signals, with a signed envelope and lifecycle events. It carries the
        // door's chips too (decisions/0209), so it reads the judged settings and speaks the declared locale.
        $topbar = new \Milpa\DesktopApp\Live\Topbar($this->liveSecret('signing'), $data, $events, $settings, $catalog);
        $this->container->registerService(\Milpa\DesktopApp\Live\Topbar::class, $topbar);

        // The main tablist is the shell's third pure-Milpa-Components surface (greenhouse decisions/0189): the
        // tablist declares the shared `desktop.tab` signal; the panes and composer dock project it.
        $tabs = new \Milpa\DesktopApp\Live\Tabs($this->liveSecret('signing'), $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Tabs::class, $tabs);

        // The Work board is the shell's fourth pure-Milpa-Components surface (greenhouse decisions/0189): a
        // projection component with a signed envelope and lifecycle events; drag-drop persists via /desktop/work.
        $workBoard = new \Milpa\DesktopApp\Live\WorkBoard($this->liveSecret('signing'), $data, $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\WorkBoard::class, $workBoard);

        // The Activity tab is the shell's fifth pure-Milpa-Components surface (greenhouse decisions/0189): the
        // session's live fact stream + a counter projection, as a signed component with lifecycle events.
        $activity = new \Milpa\DesktopApp\Live\Activity($this->liveSecret('signing'), $data, $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Activity::class, $activity);

        // The Context tab is the shell's sixth pure-Milpa-Components surface (greenhouse decisions/0189): the
        // container of plugin-contributed panels, as a signed component with lifecycle events.
        $context = new \Milpa\DesktopApp\Live\Context($this->liveSecret('signing'), $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Context::class, $context);

        // The consent gate is the shell's seventh and last pure-Milpa-Components surface (greenhouse
        // decisions/0189): the durable question, a signed component whose visibility is a shared signal.
        $gate = new \Milpa\DesktopApp\Live\Gate($this->liveSecret('signing'), $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Gate::class, $gate);

        // The conversation's message types become Milpa Components too (greenhouse decisions/0191). The first:
        // the thinking block — a declared component whose prototype the shell clones per turn and feeds live.
        $thinking = new \Milpa\DesktopApp\Live\Thinking($this->liveSecret('signing'), $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Thinking::class, $thinking);

        // The agent message is a component too (greenhouse decisions/0191): it carries its foot tools — copy
        // the answer, regenerate it — and a plugin adds more by hooking its render events.
        $agentMessage = new \Milpa\DesktopApp\Live\AgentMessage($this->liveSecret('signing'), $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\AgentMessage::class, $agentMessage);

        // The plainer message types (user, tool, task, system) as components too (greenhouse decisions/0191).
        $messages = new \Milpa\DesktopApp\Live\MessagePrototypes($this->liveSecret('signing'), $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\MessagePrototypes::class, $messages);

        // The conversation itself is a component that composes the message components (greenhouse decisions/0191).
        $conversation = new \Milpa\DesktopApp\Live\Conversation($this->liveSecret('signing'), $events);
        $this->container->registerService(\Milpa\DesktopApp\Live\Conversation::class, $conversation);

        $this->container->registerService(ShellController::class, new ShellController($events, $mercure, $data, $composerField, $sidebar, $topbar, $tabs, $workBoard, $activity, $context, $gate, $thinking, $agentMessage, $messages, $conversation, $settings, $catalog));

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

    /**
     * The shell and its live event feed — both served over HTTP for a host to load at a real origin — each
     * carrying the EFFECTIVE gate ({@see DesktopSettings::effectiveMiddleware()}, greenhouse decisions/0209):
     * the declared stack when every entry names a PSR-15 middleware class (an empty list included), loopback-only
     * the moment the declaration is anything else. The assets (`/desktop/assets/*`) carry none: public package
     * files, and a JSON refusal to a `<link>` or `<script>` would break the page silently. The export is gated.
     */
    public function routes(): array
    {
        $middleware = $this->settings()->effectiveMiddleware();

        return [
            new Route(
                path: '/desktop',
                methods: HttpMethod::GET,
                name: 'desktop.shell',
                middleware: $middleware,
                handler: new HandlerReference(ShellController::class, 'shell'),
            ),
            new Route(
                path: '/desktop/events',
                methods: HttpMethod::GET,
                name: 'desktop.events',
                middleware: $middleware,
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
                middleware: $middleware,
                handler: new HandlerReference(DataController::class, 'data'),
            ),
            new Route(
                path: '/desktop/export',
                methods: HttpMethod::GET,
                name: 'desktop.export',
                middleware: $middleware,
                handler: new HandlerReference(DataController::class, 'export'),
            ),
            new Route(
                path: '/desktop/live',
                methods: HttpMethod::POST,
                name: 'desktop.live',
                middleware: $middleware,
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
                middleware: $middleware,
                handler: new HandlerReference(MutationController::class, 'saveSettings'),
            ),
            new Route(
                path: '/desktop/sessions',
                methods: HttpMethod::POST,
                name: 'desktop.sessions.create',
                middleware: $middleware,
                handler: new HandlerReference(MutationController::class, 'createSession'),
            ),
            new Route(
                path: '/desktop/work',
                methods: HttpMethod::POST,
                name: 'desktop.work.move',
                middleware: $middleware,
                handler: new HandlerReference(MutationController::class, 'moveWork'),
            ),
        ];
    }

    /**
     * The door as the app declared it, judged (greenhouse decisions/0209): `desktop.middleware` and
     * `desktop.locale` from the runtime's config bag, the defaults when the plugin has no bag (booted
     * without a kernel, as in unit tests) — read on demand, so `routes()` answers before `boot()` too.
     */
    public function settings(): DesktopSettings
    {
        $config = $this->container->get(Config::class);

        return DesktopSettings::fromConfig($config instanceof Config ? $config : null);
    }

    /**
     * The backing services this plugin needs the host to run (greenhouse decisions/0201): the Mercure hub the
     * shell and the agent sessions stream through. Declared, not started — an admin panel lists it, probes its
     * port and projects a compose fragment; running it is the operator's call. The declaration reads the
     * wiring's `desktop.mercure.*` keys (plus the optional, declaration-only `cors_origin`), so the hub it
     * describes is the hub the app publishes to; the keys travel as secret config references, never as values.
     *
     * @return list<ServiceDeclaration>
     */
    public function services(): array
    {
        $config = $this->container->get(Config::class);

        return [MercureServiceDeclaration::fromConfig($config instanceof Config ? $config : null)];
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
