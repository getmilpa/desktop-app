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
use Milpa\DesktopApp\Admin\AdminGuest;
use Milpa\DesktopApp\Admin\AgentView;
use Milpa\DesktopApp\Admin\AgentViewComponent;
use Milpa\DesktopApp\Controllers\AssetsController;
use Milpa\DesktopApp\Controllers\DataController;
use Milpa\DesktopApp\Controllers\EventsController;
use Milpa\DesktopApp\Controllers\LiveController;
use Milpa\DesktopApp\Controllers\MutationController;
use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\Live\ComposerField;
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\Live\Contracts\Component\DeclaresComponents;
use Milpa\DesktopApp\Data\DesktopStore;
use Milpa\DesktopApp\Http\LoopbackOnlyMiddleware;
use Milpa\DesktopApp\Live\DesktopAssets;
use Milpa\DesktopApp\Live\DesktopComponents;
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
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Stack\ServiceDeclaration;
use Milpa\Runtime\Stack\StackProviderInterface;
use Milpa\Runtime\Support\RootResolver;

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
 *
 * And it is the admin's GUEST (greenhouse decisions/0210): when milpa/admin is installed, the panel finds this
 * plugin among the booted ones — by `instanceof` its `AdminSectionProvider`, which {@see AdminGuest} carries
 * only when the admin is there, so a house without the admin boots untouched — and lists ONE section, «Agent»:
 * the shell in embed mode ({@see ShellController::EMBED_PARAM}) as one region inside the admin's main, behind
 * the same door. The Desktop names no dependency on the admin; it honors the admin's contract when asked.
 */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'DesktopApp',
    type: 'Web',
)]
final class DesktopAppPlugin implements PluginInterface, RouteProviderInterface, StackProviderInterface, AdminGuest, DeclaresComponents
{
    /**
     * The components this plugin brings, so `components:catalogue` can name them and say they are
     * ours (greenhouse decisions/0214).
     *
     * It is a SECOND list beside the shell's `declare()` calls, and a second list is a lie waiting
     * to happen — so `DeclarationMatchesTheShellTest` asserts the two agree. Folding the shell's
     * calls into a loop is not possible here: each carries its own paint callable, which is what
     * makes a surface a surface.
     */
    public const array COMPONENTS = [
        Live\ActivityComponent::class,
        Live\AgentMessageComponent::class,
        Live\AuthOverlayComponent::class,
        Live\CapabilitiesScreenComponent::class,
        Live\ComposerBarComponent::class,
        Live\ComposerMessageComponent::class,
        Live\ContextComponent::class,
        Live\ConversationComponent::class,
        Live\DecisionsInboxComponent::class,
        Live\GateComponent::class,
        Live\ResultClaimComponent::class,
        Live\ScreenPreviewComponent::class,
        Live\SessionStripComponent::class,
        Live\SettingsScreenComponent::class,
        Live\SidebarComponent::class,
        Live\SkillsScreenComponent::class,
        Live\StatusBarComponent::class,
        Live\SystemNoticeComponent::class,
        Live\TabsComponent::class,
        Live\TaskComponent::class,
        Live\ThinkingComponent::class,
        Live\ToolCallComponent::class,
        Live\TopbarComponent::class,
        Live\UserMessageComponent::class,
        Live\WorkBoardComponent::class,
        AgentViewComponent::class,
    ];

    /**
     * The component definitions this plugin declares.
     *
     * {@see AgentViewComponent} is listed explicitly because it never enters the shell's registry —
     * it is built inside `AgentView::of()` for the admin's guest section — and a component nobody
     * can discover is a capability nobody can use.
     *
     * @return list<class-string<\Milpa\Live\Contracts\Component\ComponentDefinitionInterface>>
     */
    public function declaredComponents(): array
    {
        return self::COMPONENTS;
    }

    /** A plugin dispatches this (with a {@see ShellEvent} in `payload['shellEvent']`) to push a live update. */
    public const CHANGED_EVENT = 'desktop.shell.changed';

    /** Where the shell is mounted — not configurable: every route below hangs from it, and the admin section points at it. */
    public const SHELL_PATH = '/desktop';

    /** The sign-in door the admin section offers a signed-out human — app-runtime's default, the one the shell's guard reads from the 401 too. */
    public const SIGNIN_PATH = '/webauthn/signin';

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
        // milpa/live — the framework's official UI system — powers the whole shell (greenhouse decisions/0189,
        // 0211). ONE registry holds every Desktop component and its renderer: the shell composes the page
        // through it and `POST /desktop/live` is built over the SAME one, so an interaction re-renders through
        // the renderer that painted the surface. The registry is extensible: an agent or a human declares new
        // components on it the same way the shell declares its own.
        $desktopComponents = new DesktopComponents($this->liveSecret('signing'), $this->liveSecret('csrf'), $events);
        $this->container->registerService(DesktopComponents::class, $desktopComponents);
        $composerField = new ComposerField($this->liveSecret('signing'), $this->liveSecret('csrf'), $events, $desktopComponents, $catalog);
        $this->container->registerService(ComposerField::class, $composerField);
        $this->container->registerService(LiveController::class, new LiveController($desktopComponents->endpoint()));

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
        $conversation = new \Milpa\DesktopApp\Live\Conversation($this->liveSecret('signing'), $events, $data, $catalog);
        $this->container->registerService(\Milpa\DesktopApp\Live\Conversation::class, $conversation);

        // The session strip of embed mode (greenhouse decisions/0210) is a component too (decisions/0189): the
        // sidebar's reach in one row when the sidebar is folded, signed, with lifecycle events.
        $sessionStrip = new \Milpa\DesktopApp\Live\SessionStrip($this->liveSecret('signing'), $data, $events, $catalog);
        $this->container->registerService(\Milpa\DesktopApp\Live\SessionStrip::class, $sessionStrip);

        // The two screens that were still raw HTML in the shell's template become declared views too
        // (greenhouse decisions/0211, phase B): the Settings screen — whose Save says «Saved» only when
        // the door did — and the entry overlay, the one ceremony every «New session» control runs.
        $settingsScreen = new \Milpa\DesktopApp\Live\SettingsScreen($this->liveSecret('signing'), $data, $events, $catalog);
        $this->container->registerService(\Milpa\DesktopApp\Live\SettingsScreen::class, $settingsScreen);
        $authOverlay = new \Milpa\DesktopApp\Live\AuthOverlay($this->liveSecret('signing'), $data, $events, $catalog);
        $this->container->registerService(\Milpa\DesktopApp\Live\AuthOverlay::class, $authOverlay);

        // The composer bar is a declared view too (greenhouse decisions/0211, phase C): the last surface the
        // shell hand-stitched. Its markup is a renderer's, its behaviour `desktop-composer.js`, and the mode
        // its chip shows travels in a signed envelope like every other component's state.
        $composerBar = new \Milpa\DesktopApp\Live\ComposerBar($this->liveSecret('signing'), $data, $composerField, $events, $catalog);
        $this->container->registerService(\Milpa\DesktopApp\Live\ComposerBar::class, $composerBar);

        $this->container->registerService(ShellController::class, new ShellController($events, $mercure, $data, $composerField, $sidebar, $topbar, $tabs, $workBoard, $activity, $context, $gate, $thinking, $agentMessage, $messages, $conversation, $settings, $catalog, $sessionStrip, $settingsScreen, $authOverlay, $composerBar, $desktopComponents));

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
                path: self::SHELL_PATH,
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
            // Per-component files (greenhouse decisions/0211): `/desktop/assets/c/<component>.css|js`. ONE
            // route family — the placeholder captures the whole last segment, so `<name>.css` and `<name>.js`
            // both land here and {@see DesktopAssets::path()} decides which package file, if any, they name.
            // Public like the design-system stylesheets: a JSON 401 to a `<link>` breaks the page in silence.
            new Route(
                path: DesktopAssets::BASE . '{file}',
                methods: HttpMethod::GET,
                name: 'desktop.assets.component',
                handler: new HandlerReference(AssetsController::class, 'component'),
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
     * The Desktop's one section in the admin panel: «Agent» — the conversation composed INLINE in the
     * panel's own document, behind the same door (greenhouse decisions/0211, slice 3).
     *
     * The section declares a whole VIEW ({@see AgentView}), not one component: the region's root plus every
     * `desktop-*` component behind it, with the SHELL's own definitions and renderers, the props they mount
     * with and the signals the page must seed. The admin registers the tree under a layer of its own,
     * compiles it into main, emits ONE runtime for the page — every file those renderers declared, each
     * once — and serves them all from its own live wire. The iframe of decisions/0210 is gone with it.
     *
     * Called by milpa/admin at request time, and by nothing else: without the admin there is nobody to call
     * this, and `AdminSection` would not even be loadable.
     *
     * @return list<\Milpa\Admin\Section\AdminSection>
     */
    public function adminSections(): array
    {
        $settings = $this->settings();
        $catalog = $settings->catalog();
        // Asked of the UNDERLYING container, like the gate above: the wrapper's has() also says yes to
        // anything it could auto-wire, and neither of these can be auto-wired — a plugin whose boot() never
        // ran would fatal inside the admin's discovery instead of declaring the section it can declare.
        $registered = $this->container->getContainer();
        $live = $registered->has(DesktopComponents::class) ? $this->container->get(DesktopComponents::class) : null;
        $data = $registered->has(DesktopData::class) ? $this->container->get(DesktopData::class) : null;

        return [
            \Milpa\Admin\Section\AdminSection::ofView(
                id: AgentViewComponent::SECTION,
                title: $catalog->tr('agent.title'),
                view: AgentView::of(
                    $live instanceof DesktopComponents ? $live : new DesktopComponents($this->liveSecret('signing'), $this->liveSecret('csrf')),
                    $settings,
                    $catalog,
                    $data instanceof DesktopData ? $data : null,
                    self::SHELL_PATH,
                    self::SIGNIN_PATH,
                ),
                order: 60,
                group: 'agent',
                icon: '◈',
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

    /**
     * Where this app lives — asked, or walked to, never taken from the working directory.
     *
     * The two paths below used to default to `getcwd()`, and what that resolves to depends on HOW the app
     * was launched: `php -S … -t public public/router.php` — the form this README documents — leaves the
     * working directory alone, but the shorter `php -S … -t public` makes PHP's built-in server chdir()
     * into the DOCROOT on every request. Under that form these defaults put the app's settings and its
     * whole SESSION STORE inside `public/`, where they are served.
     *
     * A default that changes meaning with the command line is not a default, it is a trap — and the same
     * shape, in `milpa/app-runtime`, put passkey credentials and one-time WebAuthn challenges on the public
     * web (greenhouse evidence/0535). So: the kernel first, and failing that the platform's own
     * {@see RootResolver}, which walks UP to the nearest composer.json. From `public/` that lands on the app.
     *
     * It asks the PSR-11 REGISTRY rather than `DIContainerInterface::has()`, which answers true for any
     * auto-wirable class and would hand back a kernel rooted wherever that constructor decided
     * (greenhouse evidence/0522).
     */
    private function root(): string
    {
        if ($this->container->getContainer()->has(Kernel::class)) {
            $kernel = $this->container->get(Kernel::class);

            if ($kernel instanceof Kernel) {
                return $kernel->root();
            }
        }

        return (new RootResolver())->resolve();
    }

    /** Where persisted Desktop settings live: `desktop.settings.path` in config, else `.milpa/desktop-settings.json`. */
    private function settingsPath(): string
    {
        $config = $this->container->get(Config::class);
        $configured = $config instanceof Config ? $config->get('desktop.settings.path') : null;

        return is_string($configured) && $configured !== '' ? $configured : $this->root() . '/.milpa/desktop-settings.json';
    }

    /** Where the app's session store lives: `desktop.sessions.path` in config, else `.milpa/sessions/`. */
    private function sessionsPath(): string
    {
        $config = $this->container->get(Config::class);
        $configured = $config instanceof Config ? $config->get('desktop.sessions.path') : null;

        return is_string($configured) && $configured !== '' ? $configured : $this->root() . '/.milpa/sessions';
    }

    /** Where the shared event log lives: `desktop.events.log` in config, else a per-app temp file. */
    /**
     * The HMAC secret every Desktop component signs its state envelope and its CSRF token with.
     *
     * ONE signing key per page (greenhouse decisions/0211): `desktop.live.<kind>_secret` still wins when the
     * app declares it, but the DEFAULT is now the house's own `live.secret` — the key every other milpa/live
     * endpoint in the app verifies with, so an envelope signed by a Desktop surface is not, to the house's
     * endpoint, a tampered one. Only when the house declares neither does it fall back to a stable value
     * derived from this package's path, which is a per-install default and not a secret: declare `live.secret`
     * (or `desktop.live.*_secret`) for a real deployment.
     */
    private function liveSecret(string $kind): string
    {
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config) {
            return hash('sha256', __DIR__ . '|milpa-live|' . $kind);
        }

        $configured = $config->get('desktop.live.' . $kind . '_secret');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $house = $config->get('live.secret');
        if (is_string($house) && $house !== '') {
            return $house;
        }

        return hash('sha256', __DIR__ . '|milpa-live|' . $kind);
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
