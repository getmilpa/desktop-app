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

namespace Milpa\DesktopApp\Controllers;

use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\DesktopSettings;
use Milpa\DesktopApp\Http\RequestPrincipal;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\ActivityComponent;
use Milpa\DesktopApp\Live\AgentMessageComponent;
use Milpa\DesktopApp\Live\AuthOverlay;
use Milpa\DesktopApp\Live\AuthOverlayComponent;
use Milpa\DesktopApp\Live\CapabilityCatalogueView;
use Milpa\DesktopApp\Live\CommandListView;
use Milpa\DesktopApp\Live\ComposerField;
use Milpa\DesktopApp\Live\ContextComponent;
use Milpa\DesktopApp\Live\ConversationComponent;
use Milpa\DesktopApp\Live\DecisionsInboxView;
use Milpa\DesktopApp\Live\DesktopAssets;
use Milpa\DesktopApp\Live\DesktopComponents;
use Milpa\DesktopApp\Live\GateComponent;
use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\DesktopApp\Live\ResultClaimComponent;
use Milpa\DesktopApp\Live\RolesView;
use Milpa\DesktopApp\Live\ScreenPreviewView;
use Milpa\DesktopApp\Live\SessionStrip;
use Milpa\DesktopApp\Live\SessionStripComponent;
use Milpa\DesktopApp\Live\SettingsScreen;
use Milpa\DesktopApp\Live\SettingsScreenComponent;
use Milpa\DesktopApp\Live\SidebarComponent;
use Milpa\DesktopApp\Live\SkillsView;
use Milpa\DesktopApp\Live\SystemNoticeComponent;
use Milpa\DesktopApp\Live\TabsComponent;
use Milpa\DesktopApp\Live\TaskComponent;
use Milpa\DesktopApp\Live\ThinkingComponent;
use Milpa\DesktopApp\Live\ToolCallComponent;
use Milpa\DesktopApp\Live\TopbarComponent;
use Milpa\DesktopApp\Live\UserMessageComponent;
use Milpa\DesktopApp\Live\WorkBoardComponent;
use Milpa\DesktopApp\ShellComposition;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Support\ClientRuntime;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\ComponentContext;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the Milpa Desktop dashboard — the real UI, built from the Milpa design system (greenhouse decisions/0479).
 *
 * The whole Desktop app frame implemented from the canonical wireframes ("Wireframes de Milpa Design"): the
 * window chrome, the `mui-shell` grid (sidebar with sessions + a topbar + tabbed main), the composer and the
 * status bar — styled by the vendored `@milpa/design` system (`tierra/oro/olivo` tokens, `mui-*` components,
 * Space Grotesk / Space Mono), dark-first with a light toggle, served by {@see AssetsController}.
 *
 * Every panel is a component on the {@see runtimeScript()} client runtime: the consent gate (the design's
 * `mui-gate`, rendered live when an agent parks one), the Activity tab (the live event stream), and any panel
 * a plugin contributes through {@see COMPOSE_EVENT} with `addPanel()`, driven live via `MilpaShell.panel()`.
 * When a Mercure hub is wired ({@see MercureConfig}) the UI updates with no poll; the passkey ceremony is
 * same-origin. UI, UX and DX, all Milpa components.
 *
 * The Desktop stands behind the same door as the admin (greenhouse decisions/0209): who is signed in is
 * whatever the gate in front of the route left on the request ({@see RequestPrincipal}) — the shell reads no
 * cookie and mints no identity — and every `fetch()` the shell makes passes through one client guard, so a
 * gate's 401 sends the browser to sign in, a 403 is told, and «Saved» is only ever said on a 2xx.
 *
 * EMBED MODE (greenhouse decisions/0210): `GET /desktop?embed=1` — the same route, the same door, no new route —
 * serves the SAME page with `data-embed="1"` on the root element, and CSS folds the chrome: the window strip,
 * the sidebar, the topbar and the status bar are not visible, the main takes the whole grid. The DOM is KEPT —
 * every element id the shell script looks up at boot is still in the document, so the script contract does not
 * change — and the sessions stay reachable through a compact strip above the conversation
 * ({@see SessionStripView}) rendered only in embed mode. The links that would open a chrome screen inside the
 * host are not rendered. Whoever embeds the shell (the admin's Agent section) is a same-origin frame; the
 * guard's `next` carries the path AND the query, so a sign-in round trip lands back in embed mode.
 */
final class ShellController
{
    /** The event other plugins subscribe to (in their `boot()`) to contribute dashboard panels. */
    public const COMPOSE_EVENT = 'desktop.shell.compose';

    /** The query flag that folds the chrome: `?embed=1` (greenhouse decisions/0210). Only `1` counts. */
    public const EMBED_PARAM = 'embed';

    /**
     * The runtime files this page loads, at the URLs the Desktop serves them from — what
     * {@see LiveBoot::html()} emits in place of the hand-written `<script src>` tags the shell used to
     * carry (greenhouse decisions/0211).
     *
     * @var array<string, string>
     */
    private const array RUNTIME_URLS = [
        ClientRuntime::LOCAL => '/desktop/assets/milpa-live.js',
        ClientRuntime::REMOTE => '/desktop/assets/milpa-live-remote.js',
        ClientRuntime::ALPINE => '/desktop/assets/alpine.min.js',
    ];

    /** The Desktop's ONE component registry — the page's compiler and `/desktop/live` share it. */
    private readonly DesktopComponents $live;

    public function __construct(
        private readonly MilpaEventDispatcherInterface $events,
        private readonly ?MercureConfig $mercure = null,
        private readonly ?DesktopData $data = null,
        private readonly ?ComposerField $composerField = null,
        private readonly ?\Milpa\DesktopApp\Live\Sidebar $sidebar = null,
        private readonly ?\Milpa\DesktopApp\Live\Topbar $topbar = null,
        private readonly ?\Milpa\DesktopApp\Live\Tabs $tabs = null,
        private readonly ?\Milpa\DesktopApp\Live\WorkBoard $workBoard = null,
        private readonly ?\Milpa\DesktopApp\Live\Activity $activity = null,
        private readonly ?\Milpa\DesktopApp\Live\Context $context = null,
        private readonly ?\Milpa\DesktopApp\Live\Gate $gate = null,
        private readonly ?\Milpa\DesktopApp\Live\Thinking $thinking = null,
        private readonly ?\Milpa\DesktopApp\Live\AgentMessage $agentMessage = null,
        private readonly ?\Milpa\DesktopApp\Live\MessagePrototypes $messages = null,
        private readonly ?\Milpa\DesktopApp\Live\Conversation $conversation = null,
        private readonly ?DesktopSettings $settings = null,
        private readonly ?Catalog $catalog = null,
        private readonly ?SessionStrip $sessionStrip = null,
        private readonly ?SettingsScreen $settingsScreen = null,
        private readonly ?AuthOverlay $authOverlay = null,
        ?DesktopComponents $live = null,
    ) {
        $this->live = $live ?? $this->composerField?->registry() ?? new DesktopComponents(
            hash('sha256', __DIR__ . '|milpa-live|signing'),
            hash('sha256', __DIR__ . '|milpa-live|csrf'),
            $events,
        );
        $this->declareSurfaces();
    }

    /**
     * Declare every shell surface on the Desktop's one registry (greenhouse decisions/0211).
     *
     * Each `desktop-*` component is bound to its definition and to a renderer that paints it — the
     * surface service that owns its markup, its lifecycle events and its signed envelope — and declares
     * exactly that component's client files. From here the page is COMPOSED (`<milpa-desktop-sidebar/>`
     * through {@see XhtmlComponentCompiler}) instead of stitched, and the same renderers answer
     * `POST /desktop/live` when an interaction re-paints a surface.
     *
     * Declared eagerly, in the constructor: the registry is shared with the live endpoint, so a surface
     * must be resolvable there whether or not the page has been rendered yet.
     */
    private function declareSurfaces(): void
    {
        $this->live->declare(new SidebarComponent(), fn (array $props): string => $this->sidebarOf()->render(($props['chrome'] ?? true) !== false));
        $this->live->declare(new TopbarComponent(), fn (array $props): string => $this->topbarOf()->render(\is_string($props['principal'] ?? null) && $props['principal'] !== '' ? $props['principal'] : null));
        $this->live->declare(new TabsComponent(), fn (array $props): string => $this->tabsOf()->render());
        $this->live->declare(new SessionStripComponent(), fn (array $props): string => $this->sessionStripOf()->render());
        $this->live->declare(new ConversationComponent(), fn (array $props): string => $this->conversationOf()->render());
        $this->live->declare(new GateComponent(), fn (array $props): string => $this->gateOf()->render());
        $this->live->declare(new WorkBoardComponent(), fn (array $props): string => $this->workBoardOf()->render());
        $this->live->declare(new ActivityComponent(), fn (array $props): string => $this->activityOf()->render());
        $this->live->declare(new ContextComponent(), fn (array $props): string => $this->contextOf()->render(\is_array($props['sections'] ?? null) ? $props['sections'] : []));
        $this->live->declare(new ThinkingComponent(), fn (array $props): string => $this->thinkingOf()->render());
        $this->live->declare(new AgentMessageComponent(), fn (array $props): string => $this->agentMessageOf()->render());
        $this->live->declare(new UserMessageComponent(), fn (array $props): string => $this->messages()->user());
        $this->live->declare(new ToolCallComponent(), fn (array $props): string => $this->messages()->tool());
        $this->live->declare(new TaskComponent(), fn (array $props): string => $this->messages()->task());
        $this->live->declare(new SystemNoticeComponent(), fn (array $props): string => $this->messages()->system());
        $this->live->declare(new ResultClaimComponent(), fn (array $props): string => $this->messages()->resultClaim());
        // The two screens phase B took out of the template (greenhouse decisions/0211): the Settings screen
        // and the entry overlay were the last raw HTML the shell hand-wrote.
        $this->live->declare(new SettingsScreenComponent(), fn (array $props): string => $this->settingsScreenOf()->render());
        $this->live->declare(new AuthOverlayComponent(), fn (array $props): string => $this->authOverlayOf()->render());
    }

    /** The Desktop's one component registry — what `POST /desktop/live` is built over. */
    public function components(): DesktopComponents
    {
        return $this->live;
    }

    /** The catalog the shell speaks in — the injected one, else the declared locale's, else English (greenhouse decisions/0209). */
    private function catalog(): Catalog
    {
        return $this->catalog ?? ($this->settings ?? new DesktopSettings())->catalog();
    }

    /**
     * The catalog's messages as JSON for the client script (`#milpa-desktop-i18n`), so the guard's notices and
     * the settings badge say the same words the server does. HEX-escaped: no `<` survives, so no message can
     * close the script element.
     */
    private function i18nJson(): string
    {
        return (string) json_encode($this->catalog()->all(), \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** The plainer message prototypes (user/tool/task/system), or a fallback set (greenhouse decisions/0191). */
    private function messages(): \Milpa\DesktopApp\Live\MessagePrototypes
    {
        return $this->messages ?? new \Milpa\DesktopApp\Live\MessagePrototypes('desktop-messages-fallback', $this->events);
    }

    /** Serve the dashboard, composed with every plugin's contributed panels. */
    public function shell(ServerRequestInterface $request): ResponseInterface
    {
        // A sidebar click selects a session via `?session=<id>`; the data seam loads that one's counters,
        // context and conversation (an unknown or malformed id is ignored — the newest session stands).
        $session = self::queryParam($request, 'session');
        if (\is_string($session)) {
            $this->data?->select($session);
        }
        // Embed mode (greenhouse decisions/0210): the flag folds the chrome; the DOM and the door are the same.
        $embed = self::queryParam($request, self::EMBED_PARAM) === '1';

        $composition = new ShellComposition();
        $this->events->dispatch(self::COMPOSE_EVENT, ['composition' => $composition]);

        // The agent session this Desktop drives (greenhouse decisions/0190): a stable id, kept in a cookie so
        // reloads continue the SAME governed session. Its `session.*` events ride the exact topic below.
        $agentSid = $request->getCookieParams()['milpa_agent_sid'] ?? null;
        $agentSid = \is_string($agentSid) && $agentSid !== '' ? $agentSid : 'desk-' . bin2hex(random_bytes(8));

        $cookies = [];
        if ($this->mercure !== null) {
            $cookies[] = 'milpa_agent_sid=' . $agentSid . '; Path=/; SameSite=Lax';
            // The hub reads the subscriber JWT from this cookie; the browser sends it with EventSource. It is
            // scoped to the shell topic AND this session's exact stream topic (greenhouse decisions/0190).
            $jwt = $this->mercure->subscriberJwt([\Milpa\DesktopApp\Live\MercureConfig::sessionTopic($agentSid)]);
            $cookies[] = 'mercureAuthorization=' . $jwt . '; Path=/; SameSite=Lax';
        }

        // The milpa/live boot (greenhouse decisions/0211): the SERVER issues the page session and its CSRF
        // token, `LiveBoot` is the one place they are written, and the runtime echoes the session id in every
        // request body — so `POST /desktop/live` reads it from there and no cookie carries a page's session.
        $boot = LiveBoot::issue($this->live->csrf(), ComposerField::ROUTE);

        // The only cookies the shell still sets are the hub's (greenhouse decisions/0190) — the live session
        // travels in the boot now, not in `milpa_live_sid` (decisions/0211), so a Desktop with no hub sets none.
        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'];
        if ($cookies !== []) {
            $headers['Set-Cookie'] = $cookies;
        }

        // Who the gate let in (greenhouse decisions/0209): read from the attribute the gate leaves on the request,
        // never from a cookie — the Desktop invents no identity; the topbar shows the actor, or nobody.
        return new Response(200, $headers, $this->html($composition, $boot, $agentSid, RequestPrincipal::of($request), $embed));
    }

    /**
     * One query parameter of the request, read the way app-runtime's sign-in page reads `next`
     * (`PasskeyController::signinPage()`): the parsed params when they carry the KEY, else the URI's own query
     * string parsed here — so `?embed=1` and `?session=` are read however the request was built, a bare PSR-7
     * request included. Per key, not per request: a parsed bag that lacks the key does not hide the URI's.
     */
    private static function queryParam(ServerRequestInterface $request, string $name): mixed
    {
        $params = $request->getQueryParams();
        if (!\array_key_exists($name, $params)) {
            parse_str($request->getUri()->getQuery(), $params);
        }

        return $params[$name] ?? null;
    }

    private function html(ShellComposition $composition, LiveBoot $boot, string $agentSid = '', ?string $principal = null, bool $embed = false): string
    {
        // The page is COMPOSED through the compiler over the ONE registry (greenhouse decisions/0211): every
        // surface is `<milpa-desktop-*/>` resolved to its definition + renderer, and every declaring renderer's
        // files are collected here — so `LiveBoot::html()` emits each of them exactly once, in this order.
        // The shared guard module leads: it is the runtime module every component module reaches for.
        $assets = new ClientAssets(scripts: [DesktopAssets::url(DesktopAssets::GUARD, 'js')]);
        $compiler = $this->live->compiler([
            'desktop-sidebar' => ['chrome' => !$embed],
            'desktop-topbar' => ['principal' => $principal ?? ''],
            'desktop-context' => ['sections' => $composition->sections()],
        ]);
        $paint = function (string $component) use ($compiler, &$assets): string {
            $result = $compiler->compile('<milpa-' . $component . '/>', new ComponentContext(componentId: 'shell', route: ComposerField::ROUTE));
            $assets = $assets->merge($result->clientAssets());

            return $result->output;
        };

        // Painted in document order, so the declared stylesheets apply in the order the surfaces appear.
        $sidebar = $paint('desktop-sidebar');
        $topbar = $paint('desktop-topbar');
        $sessionStrip = $embed ? $paint('desktop-session-strip') : '';
        $tabs = $paint('desktop-tabs');
        $conversation = $paint('desktop-conversation');
        $gate = $paint('desktop-gate');
        $work = $paint('desktop-work-board');
        $activity = $paint('desktop-activity');
        $context = $paint('desktop-context');
        $thinking = $paint('desktop-thinking');
        $agentMessage = $paint('desktop-agent-message');
        $userMessage = $paint('desktop-user-message');
        $toolMessage = $paint('desktop-tool-call');
        $taskMessage = $paint('desktop-task');
        $systemMessage = $paint('desktop-system-notice');
        $resultMessage = $paint('desktop-result-claim');
        $settings = $paint('desktop-settings');
        $auth = $paint('desktop-auth');

        return str_replace(
            [
                '<!--RUNTIME-->', '<!--CONTEXT-->', '<!--CAPABILITIES-->', '<!--SKILLS-->', '<!--ROLES-->', '<!--SCREENS-->', '<!--LIVEROUTE-->', '<!--DECISIONS-->', '<!--INTERRUPTED-->', '<!--SETTINGS-->',
                '<!--SIDEBAR-->', '<!--STATUS-->', '<!--WORK-->', '<!--ACTIVITY-->', '<!--COMPOSER-->', '<!--AUTH-->', '<!--LIVE-->', '<!--TOPBAR-->', '<!--TABS-->', '<!--GATE-->', '<!--CONVERSATION-->', '<!--THINKING-->', '<!--AGENTMSG-->', '<!--USERMSG-->', '<!--TOOLMSG-->', '<!--TASKMSG-->', '<!--SYSMSG-->', '<!--RESULTMSG-->', '<!--LIVERUNTIME-->', '<!--AGENTSID-->', '<!--COMMANDS-->',
                '<!--I18N-->', '<!--EMBED-->', '<!--SESSIONSTRIP-->',
            ],
            [
                $this->runtimeScript(), $context, $this->capabilityCatalogueHtml(), $this->skillsHtml(), $this->rolesHtml(), $this->screenPreviewHtml(), htmlspecialchars($this->data?->liveRoute() ?? '/live', ENT_QUOTES), $this->decisionsInboxHtml(), $this->interruptedNoticeHtml(), $settings,
                $sidebar, $this->statusCounters(), $work, $activity, $this->composer(), $auth, $this->connectScript($agentSid), $topbar, $tabs, $gate, $conversation, $thinking, $agentMessage, $userMessage, $toolMessage, $taskMessage, $systemMessage, $resultMessage, $this->liveRuntime($boot, $assets), htmlspecialchars($agentSid, ENT_QUOTES), $this->commandsJson(),
                $this->i18nJson(), $embed ? ' data-embed="1"' : '', $sessionStrip,
            ],
            $this->template(),
        );
    }

    /**
     * ONE runtime per page (greenhouse decisions/0211): the seeds the local runtime reads, then everything
     * `LiveBoot::html()` emits — the declared stylesheets, the boot payload, `milpa-live.js`,
     * `milpa-live-remote.js`, every declared component module (the shared guard first) and Alpine last, each
     * `defer`, each URL once. The shell hand-writes no runtime `<script>` tag any more.
     *
     * The three seed tags are emitted HERE and only here — `LiveBoot` carries the boot, not the signals — so
     * the page never has two places that could disagree about what the store starts with.
     */
    private function liveRuntime(LiveBoot $boot, ClientAssets $assets): string
    {
        $seeds = '<script id="milpa-live-signals" type="application/json">' . str_replace('</', '<\/', $this->liveSignals()) . '</script>' . "\n"
            // Nothing is remembered in the browser: the mode is seeded from the SAVED setting on every load
            // (one truth, server-side — greenhouse decisions/0202); the session summary is DERIVED.
            . '<script id="milpa-live-persist" type="application/json">[]</script>' . "\n"
            . '<script id="milpa-live-computed" type="application/json">{"session.summary":{"template":"{session.state.label} · {session.turns} turns"},"session.counters":{"template":"{session.turns} turns · {session.tool_calls} tools"},"context.usage":{"template":"{context.used}/{context.window}"},"session.status":{"template":"{session.turns} turns · {session.steps} steps · {session.tokens} tokens · {session.tool_calls} tool calls"}}</script>' . "\n";

        return $seeds . $boot->html(self::RUNTIME_URLS, $assets);
    }

    /**
     * The session strip of embed mode (greenhouse decisions/0210): the current session's goal, a `<select>` of
     * every session and a «New session» control — the sidebar's reach, in one row above the conversation, when
     * the sidebar is folded. A milpa/live component ({@see SessionStrip}) over the same data the sidebar reads;
     * a fallback is built from that data when none was injected.
     */
    private function sessionStripOf(): SessionStrip
    {
        return $this->sessionStrip ?? new SessionStrip('desktop-session-strip-fallback', $this->data, $this->events, $this->catalog());
    }

    /**
     * The capability catalogue as HTML — INSTALLED and AVAILABLE (greenhouse decisions/0193).
     *
     * The human sees the same list the agent sees ({@see DesktopData::capabilityCatalogue()}); the render
     * itself is a pure {@see CapabilityCatalogueView} so its branches are tested with fixtures, not a runtime.
     */
    private function capabilityCatalogueHtml(): string
    {
        $cat = $this->data?->capabilityCatalogue() ?? ['installed' => [], 'available' => []];

        return (new CapabilityCatalogueView())->html($cat['installed'], $cat['available']);
    }

    /**
     * The decisions inbox as HTML — the questions agents parked, across all sessions (greenhouse decisions/0195).
     *
     * Cross-session backlog: {@see DesktopData::pendingDecisions()} reads each session's parked question, and a
     * pure {@see DecisionsInboxView} renders it so the branches are tested with fixtures, not a runtime.
     */
    private function decisionsInboxHtml(): string
    {
        return (new DecisionsInboxView())->html($this->data?->pendingDecisions() ?? []);
    }

    /**
     * The agent's skills as HTML (greenhouse decisions/0197): the same list the agent reaches for, each with
     * who may invoke it. The render is a pure {@see SkillsView} tested with fixtures, not a runtime.
     */
    private function skillsHtml(): string
    {
        return (new SkillsView())->html($this->data?->skills() ?? []);
    }

    /**
     * The composer's commands (greenhouse decisions/0202): the house's own plus every user-invocable skill,
     * from {@see DesktopData::commands()} — or the house's alone when no data seam is wired, so the composer
     * always knows `/goal`, `/mode` and `/help`. One list feeds both the completion popup and the parser.
     *
     * @return list<array{name: string, kind: string, description: string, usage: string, method: string}>
     */
    private function commands(): array
    {
        return $this->data?->commands() ?? DesktopData::houseCommands();
    }

    /**
     * The command list as JSON — the `#milpa-commands` payload the composer's parser reads. HEX-encoded by
     * {@see CommandListView::json()}, so a skill's description can never close the script element.
     */
    private function commandsJson(): string
    {
        return CommandListView::json($this->commands());
    }

    /**
     * The specialist agent roles as HTML (greenhouse decisions/0197): the roles the app declares, each with the
     * skills it preloads and the tools it is denied. A pure {@see RolesView} tested with fixtures, not a runtime.
     */
    private function rolesHtml(): string
    {
        return (new RolesView())->html($this->data?->roles() ?? []);
    }

    /**
     * The declared-screen preview chips as HTML (greenhouse decisions/0197): pick one to render its live screen
     * in the preview iframe. A pure {@see ScreenPreviewView} tested with fixtures, not a runtime.
     */
    private function screenPreviewHtml(): string
    {
        return (new ScreenPreviewView())->html($this->data?->declaredScreens() ?? []);
    }

    /**
     * A notice when the current session was left mid-run (greenhouse decisions/0196).
     *
     * A fresh page load has no live run of its own, so a session whose recorded state is still a running one
     * (working / thinking / running / busy) is a run that did not finish — reported, never silently
     * auto-resumed (the lesson of greenhouse decisions/0132). Empty for a settled session.
     */
    private function interruptedNoticeHtml(): string
    {
        $state = strtolower($this->data?->counters()['state'] ?? '');
        if (!\in_array($state, ['working', 'thinking', 'running', 'busy'], true)) {
            return '';
        }

        return '<div class="milpa-interrupted" role="note">'
            . '<span class="milpa-interrupted__mark" aria-hidden="true">⚠</span>'
            . '<span>A prior run was interrupted — it was left mid-turn and did not finish. Send again to continue; nothing was auto-resumed.</span>'
            . '</div>';
    }

    /** The Settings screen surface (greenhouse decisions/0211) — the injected one, else a fallback over the same data. */
    private function settingsScreenOf(): SettingsScreen
    {
        return $this->settingsScreen ?? new SettingsScreen('desktop-settings-fallback', $this->data, $this->events, $this->catalog());
    }

    /** The entry overlay surface (greenhouse decisions/0211) — the injected one, else a fallback over the same data. */
    private function authOverlayOf(): AuthOverlay
    {
        return $this->authOverlay ?? new AuthOverlay('desktop-auth-fallback', $this->data, $this->events, $this->catalog());
    }

    /** Format a token count as "9.25K". */
    private function kfmt(int $n): string
    {
        return number_format($n / 1000, 2) . 'K';
    }

    /**
     * The composer with floating Context and Session panels (wireframe 3a) — clean data over the tight bar.
     *
     * The panels open when their figures in the composer are clicked and close when the user types; the
     * bottom status bar drops to one line. Every number is real: the context window and its usage from
     * {@see DesktopData::context()}, the session counters from {@see DesktopData::counters()}.
     */
    private function composer(): string
    {
        $ctx = $this->data?->context() ?? ['tokens' => 0, 'window' => 32768, 'used_pct' => 0, 'free' => 32768];
        $c = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];
        $model = htmlspecialchars($this->data?->model()['model'] ?? 'qwen3.8-27b', ENT_QUOTES);
        $tokens = $this->kfmt($ctx['tokens']);
        $window = $this->kfmt($ctx['window']);

        // The permission mode the composer chip shows and its menu marks as current, from settings.
        $modeLabels = ['ask' => 'Ask before changing', 'acknowledge' => 'Compatibility', 'auto' => 'Continue automatically'];
        $settings = $this->data?->settings() ?? [];
        $modeKey = \is_string($settings['mode'] ?? null) && isset($modeLabels[$settings['mode']]) ? (string) $settings['mode'] : 'ask';
        $modeLabel = $modeLabels[$modeKey];
        $modeMenu = '';
        foreach ($modeLabels as $key => $label) {
            $modeMenu .= sprintf(
                '<button type="button" role="menuitem" class="milpa-mode-opt mui-btn mui-btn--ghost mui-btn--sm mui-btn--full" data-mode="%s" data-label="%s"%s style="justify-content:flex-start;text-align:start">%s<span class="mui-badge" style="margin-inline-start:auto">%s</span></button>',
                $key,
                htmlspecialchars($label, ENT_QUOTES),
                $key === $modeKey ? ' aria-current="true"' : '',
                htmlspecialchars($label, ENT_QUOTES),
                $key,
            );
        }

        // The composer's text field IS a milpa/live component when the framework's UI system is wired
        // (greenhouse decisions/0189); otherwise a plain textarea (backwards-compatible fallback).
        $field = $this->composerField !== null
            ? $this->composerField->render()
            : '<textarea id="composer-input" class="mui-textarea" rows="2" placeholder="Write to the session…" style="border:0;outline:0;background:transparent;min-height:3rem;padding:0;font-size:var(--text-sm);font-family:var(--font-mono);width:100%;resize:none"></textarea>';
        // The command completion popup (greenhouse decisions/0202): a pure view over the list the house serves.
        $commandList = (new CommandListView())->html($this->commands());
        $free = $this->kfmt($ctx['free']);
        $pct = $ctx['used_pct'];
        $barColor = $pct < 70 ? 'var(--success)' : ($pct < 90 ? 'var(--warning)' : 'var(--danger)');

        return <<<HTML
<div class="composer-wrap" style="position:relative;margin-top:var(--space-2)">
  <div class="composer-panels" style="position:absolute;right:0;bottom:calc(100% + var(--space-3));display:flex;gap:var(--space-4);align-items:flex-end">

    <div class="composer-panel" data-panel-for="session" hidden :hidden="\$store.milpa['composer.panel'] !== 'session'" style="width:260px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface-raised);box-shadow:var(--shadow-lg);padding:var(--space-5);display:flex;flex-direction:column;gap:var(--space-4);font-family:var(--font-mono)">
      <p style="margin:0;font-size:var(--text-sm)">Session <span style="color:var(--text-muted)">· {$c['turns']} turns</span></p>
      <div style="height:1px;background:var(--border-subtle)"></div>
      <p style="margin:0;display:flex;justify-content:space-between;font-size:var(--text-2xs)"><span style="color:var(--text-secondary)">Steps</span><span>{$c['steps']}</span></p>
      <p style="margin:0;display:flex;justify-content:space-between;font-size:var(--text-2xs)"><span style="color:var(--text-secondary)">Tool calls</span><span>{$c['tool_calls']}</span></p>
      <p style="margin:0;display:flex;justify-content:space-between;font-size:var(--text-2xs)"><span style="color:var(--text-secondary)">State</span><span style="color:var(--accent-text)">{$c['state']}</span></p>
    </div>

    <div class="composer-panel" data-panel-for="context" hidden :hidden="\$store.milpa['composer.panel'] !== 'context'" style="width:300px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface-raised);box-shadow:var(--shadow-lg);padding:var(--space-5);display:flex;flex-direction:column;gap:var(--space-4);font-family:var(--font-mono)">
      <p style="margin:0;font-size:var(--text-sm)">Context <span style="color:var(--text-muted)">· {$tokens} / {$window}</span></p>
      <div class="mui-progress" role="progressbar" aria-valuenow="{$pct}" aria-valuemin="0" aria-valuemax="100" style="width:100%"><span class="mui-progress__bar" style="width:{$pct}%;background:{$barColor}"></span></div>
      <p style="margin:0;display:flex;justify-content:space-between;font-size:var(--text-2xs);color:var(--text-secondary)"><span>{$pct}% used</span><span>{$free} free</span></p>
    </div>

  </div>

  {$commandList}
  <div class="milpa-composer-box" style="border:1px solid var(--border);border-radius:22px;background:var(--surface-raised);box-shadow:var(--shadow-md);padding:var(--space-4) var(--space-5) var(--space-3)">
    {$field}
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-top:var(--space-2)">
      <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm mui-btn--icon" aria-label="attach" style="border-radius:var(--radius-full)">＋</button>
      <span style="position:relative;display:inline-flex">
        <button type="button" class="mui-badge" id="milpa-mode-chip" aria-haspopup="true" aria-expanded="false" style="cursor:pointer;border:1px solid var(--border);font:inherit;display:inline-flex;align-items:center;gap:6px"><span id="milpa-mode-label" x-data x-text="\$store.milpa['composer.mode.label']">{$modeLabel}</span><span aria-hidden="true" style="opacity:.6">▾</span></button>
        <div id="milpa-mode-menu" hidden role="menu" style="position:absolute;bottom:calc(100% + 8px);left:0;z-index:60;min-width:15rem;background:var(--surface-raised);border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-lg);padding:4px">{$modeMenu}</div>
      </span>
      <span style="margin-inline-start:auto;display:flex;align-items:center;gap:var(--space-2);font-family:var(--font-mono);font-size:var(--text-2xs)">
        <span id="milpa-charcount" aria-live="polite" style="color:var(--text-muted);min-width:0"></span>
        <button type="button" class="composer-chip" data-open-panel="session" @click="\$store.milpa['composer.panel'] = \$store.milpa['composer.panel'] === 'session' ? '' : 'session'" style="border:1px solid var(--border);border-radius:var(--radius-full);background:var(--surface);color:var(--text);padding:4px 10px;cursor:pointer;font:inherit">◈ <span x-data x-text="\$store.milpa['session.counters']">{$c['turns']} turns · {$c['tool_calls']} tools</span></button>
        <button type="button" class="composer-chip" data-open-panel="context" @click="\$store.milpa['composer.panel'] = \$store.milpa['composer.panel'] === 'context' ? '' : 'context'" style="border:1px solid var(--border);border-radius:var(--radius-full);background:var(--surface);color:var(--text);padding:4px 10px;cursor:pointer;font:inherit">▤ <span x-data x-text="\$store.milpa['context.usage']">{$tokens}/{$window}</span></button>
        <button type="button" class="mui-btn mui-btn--primary mui-btn--icon" id="milpa-send" aria-label="continue session" disabled style="border-radius:var(--radius-full)" x-data :disabled="!\$store.milpa['session.working'] && !\$store.milpa['composer.draft']" :aria-label="\$store.milpa['session.working'] ? 'stop the turn' : 'continue session'"><span x-text="\$store.milpa['session.working'] ? '■' : '↑'">↑</span></button>
      </span>
    </div>
  </div>
  <p style="margin:var(--space-2) 0 0;font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">Model: {$model} · panels open on their figures, close as you type.</p>
</div>
HTML;
    }

    /** The sidebar surface (greenhouse decisions/0189) — the injected one, else a fallback over the same data. */
    private function sidebarOf(): \Milpa\DesktopApp\Live\Sidebar
    {
        return $this->sidebar ?? new \Milpa\DesktopApp\Live\Sidebar('desktop-sidebar-fallback', $this->data, $this->events);
    }

    /** The topbar surface (greenhouse decisions/0189, 0209) — the injected one, else a fallback over the same data and door. */
    private function topbarOf(): \Milpa\DesktopApp\Live\Topbar
    {
        return $this->topbar ?? new \Milpa\DesktopApp\Live\Topbar('desktop-topbar-fallback', $this->data, $this->events, $this->settings, $this->catalog);
    }

    /** The main tablist surface (greenhouse decisions/0189) — the panes and composer dock read its `desktop.tab` signal. */
    private function tabsOf(): \Milpa\DesktopApp\Live\Tabs
    {
        return $this->tabs ?? new \Milpa\DesktopApp\Live\Tabs('desktop-tabs-fallback', $this->events);
    }

    /** The initial shared signals, seeded into the page — one truth projected across the UI (decisions/0189). */
    private function liveSignals(): string
    {
        $modeLabels = ['ask' => 'Ask before changing', 'acknowledge' => 'Compatibility', 'auto' => 'Continue automatically'];
        $settings = $this->data?->settings() ?? [];
        $modeKey = \is_string($settings['mode'] ?? null) && isset($modeLabels[$settings['mode']]) ? (string) $settings['mode'] : 'ask';
        $counters = $this->data?->counters();
        $ctx = $this->data?->context() ?? ['tokens' => 0, 'window' => 32768];

        // Every counter the UI shows is a SIGNAL — one truth, projected to the composer chips, the status bar
        // and the panels alike (greenhouse decisions/0191, Rod). The live feed and the turn update these; every
        // place that reads them updates at once. A value shown that is not a signal is a value that goes stale.
        // The mode is a signal PAIR (greenhouse decisions/0202): the VALUE every turn sends to the agent
        // (ask | acknowledge | auto) and its label for the chip. Seeded from the SAVED setting on every load —
        // the server's copy is the one truth; nothing about the mode is remembered in the browser.
        return (string) json_encode([
            'composer.mode' => $modeKey,
            'composer.mode.label' => $modeLabels[$modeKey],
            'session.state.label' => ucfirst(\is_array($counters) ? (string) $counters['state'] : 'idle'),
            'session.turns' => \is_array($counters) ? (int) $counters['turns'] : 0,
            'session.steps' => \is_array($counters) ? (int) $counters['steps'] : 0,
            'session.tokens' => \is_array($counters) ? (int) $counters['tokens'] : 0,
            'session.tool_calls' => \is_array($counters) ? (int) $counters['tool_calls'] : 0,
            'context.used' => $this->kfmt((int) $ctx['tokens']),
            'context.window' => $this->kfmt((int) $ctx['window']),
            'desktop.nav' => 'sessions',
            'desktop.tab' => 'chat',
            'desktop.gate.open' => false,
            // The couplings phase A dissolved into signals (greenhouse decisions/0211):
            //  · `session.working` replaces setWorking() poking the send button and the topbar badge — both
            //    BIND to it now, so anything else that must follow the turn binds too instead of being poked;
            //  · `composer.draft` is the other half of the send button's state (is there anything to send),
            //    so its `disabled` is a binding and not an assignment;
            //  · `ui.dismiss` is bumped by the ONE document-level click listener the guard module owns — the
            //    mode menu and the command popup consume it instead of each hanging its own listener;
            //  · `desktop.notice` is what the guard SAYS when a door answers, instead of reaching into the
            //    conversation: whoever renders notices consumes it.
            'session.working' => \is_array($counters) && strtolower((string) $counters['state']) === 'working',
            'composer.draft' => false,
            'ui.dismiss' => 0,
            'desktop.notice' => null,
            // What phase B made signals (greenhouse decisions/0211):
            //  · `composer.panel` is WHICH floating panel is open — the chips set it, both panels bind
            //    `:hidden` to it, and typing clears it, so nothing pokes a `.hidden` property;
            //  · `ui.theme` is the shell's theme in three places at once (the document, the chrome toggle,
            //    the Settings buttons) — seeded 'system', corrected by the topbar module from what was
            //    remembered, and never a second copy for the buttons to disagree with;
            //  · `desktop.auth.open` is the entry overlay's visibility, so any surface can ask for it;
            //  · `settings.saved` is what the save badge SHOWS — `{ok, text}` while a save is being
            //    reported, null once it has been. Only the door's answer ever fills it.
            'composer.panel' => '',
            'ui.theme' => 'system',
            'desktop.auth.open' => false,
            'settings.saved' => null,
        ], \JSON_UNESCAPED_SLASHES);
    }

    /** The status bar's counters — bound to the shared `session.status` signal so they update live with the
     *  composer chips and the panels (one truth, greenhouse decisions/0191). Seeded from the session (0482). */
    private function statusCounters(): string
    {
        $c = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];

        $seed = sprintf('%d turns · %d steps · %d tokens · %d tool calls', $c['turns'], $c['steps'], $c['tokens'], $c['tool_calls']);

        return '<span x-data x-text="$store.milpa[\'session.status\']">' . $seed . '</span>';
    }

    /** The Work board surface (greenhouse decisions/0189) — moving a card still persists through /desktop/work. */
    private function workBoardOf(): \Milpa\DesktopApp\Live\WorkBoard
    {
        return $this->workBoard ?? new \Milpa\DesktopApp\Live\WorkBoard('desktop-work-board-fallback', $this->data, $this->events);
    }

    /** The Activity tab surface (greenhouse decisions/0189) — facts arrive live over the hub, prepended to #milpa-activity. */
    private function activityOf(): \Milpa\DesktopApp\Live\Activity
    {
        return $this->activity ?? new \Milpa\DesktopApp\Live\Activity('desktop-activity-fallback', $this->data, $this->events);
    }

    /** The Context tab surface (greenhouse decisions/0189) — plugins contribute panels through the composition (addPanel). */
    private function contextOf(): \Milpa\DesktopApp\Live\Context
    {
        return $this->context ?? new \Milpa\DesktopApp\Live\Context('desktop-context-fallback', $this->events);
    }

    /** The consent gate surface (greenhouse decisions/0189) — its visibility is the `desktop.gate.open` signal. */
    private function gateOf(): \Milpa\DesktopApp\Live\Gate
    {
        return $this->gate ?? new \Milpa\DesktopApp\Live\Gate('desktop-gate-fallback', $this->events);
    }

    /** The conversation surface (greenhouse decisions/0191): the empty state + envelope inside the chat container. */
    private function conversationOf(): \Milpa\DesktopApp\Live\Conversation
    {
        return $this->conversation ?? new \Milpa\DesktopApp\Live\Conversation('desktop-conversation-fallback', $this->events);
    }

    /** The thinking prototype's surface (greenhouse decisions/0191): cloned per turn, fed the reasoning by events. */
    private function thinkingOf(): \Milpa\DesktopApp\Live\Thinking
    {
        return $this->thinking ?? new \Milpa\DesktopApp\Live\Thinking('desktop-thinking-fallback', $this->events);
    }

    /** The agent-message prototype's surface (greenhouse decisions/0191): cloned per answer, its foot tools delegated. */
    private function agentMessageOf(): \Milpa\DesktopApp\Live\AgentMessage
    {
        return $this->agentMessage ?? new \Milpa\DesktopApp\Live\AgentMessage('desktop-agent-message-fallback', $this->events);
    }

    /**
     * The client component runtime, always served (greenhouse decisions/0476, 0478).
     *
     * `MilpaShell` bridges the live transport to the UI, and the panel API is the DX: `on('<event>', cb)` /
     * `onAny(cb)` react to events, `panel('<id>')` returns a panel body, `onStatus(cb)` tracks the connection.
     */
    private function runtimeScript(): string
    {
        return <<<'HTML'
<script>
  window.MilpaShell = (function () {
    var byType = {}, anyHandlers = [], statusHandlers = [];
    return {
      on: function (type, cb) { (byType[type] = byType[type] || []).push(cb); },
      onAny: function (cb) { anyHandlers.push(cb); },
      onStatus: function (cb) { statusHandlers.push(cb); },
      emit: function (type, data) {
        (byType[type] || []).forEach(function (cb) { cb(data); });
        anyHandlers.forEach(function (cb) { cb(type, data); });
      },
      status: function (state) { statusHandlers.forEach(function (cb) { cb(state); }); },
      // Live decisions inbox (greenhouse decisions/0196): tick the nav badge and prepend a card when an agent
      // parks a question, so it shows without a reload. The full card (goal/facts) is rendered on next load.
      addDecision: function (question) {
        var badge = document.querySelector('[data-nav="decisions"] .mui-sidebar__item-badge');
        if (!badge) {
          var nav = document.querySelector('[data-nav="decisions"]');
          if (nav) { badge = document.createElement('span'); badge.className = 'mui-sidebar__item-badge mui-badge mui-badge--warning'; badge.style.marginInlineStart = 'auto'; badge.textContent = '0'; nav.appendChild(badge); }
        }
        if (badge) { badge.textContent = String((parseInt(badge.textContent, 10) || 0) + 1); badge.hidden = false; }
        var view = document.querySelector('[data-view="decisions"]');
        if (!view) { return; }
        var list = document.getElementById('milpa-decisions-list');
        if (!list) {
          var empty = document.getElementById('milpa-decisions-empty');
          if (empty) { empty.remove(); }
          list = document.createElement('ol'); list.className = 'mui-replay__stream'; list.id = 'milpa-decisions-list'; list.setAttribute('aria-live', 'polite');
          view.appendChild(list);
        }
        var li = document.createElement('li'); li.className = 'decision-card';
        var p = document.createElement('p'); p.className = 'decision-card__q'; p.textContent = question || 'A question is waiting for you.';
        li.appendChild(p);
        var hint = document.createElement('p'); hint.className = 'decision-card__facts'; hint.textContent = 'just now · open the conversation to answer';
        li.appendChild(hint);
        list.insertBefore(li, list.firstChild);
      },
      // A governed turn's session.* projection (greenhouse decisions/0190), translated to the events the
      // shell already handles — so one set of listeners renders both the desktop feed and the agent stream.
      session: function (env) {
        var kind = env && env.kind;
        if (kind === 'activity') {
          var st = (env.activity && env.activity.state) || '';
          if (st === 'thinking') { this.emit('session.state', { state: 'working' }); }
          else if (st === 'ready') { this.emit('session.state', { state: 'idle' }); }
          else if (st === 'tool') {
            // A tool ran: show it in the conversation and count it into the shared tool_calls signal.
            this.emit('tool.call', { name: (env.activity && env.activity.detail) || 'tool', result: (env.activity && env.activity.result) || '' });
            if (window.MilpaLive && MilpaLive.signal) { MilpaLive.signal('session.tool_calls', (parseInt(MilpaLive.signal('session.tool_calls'), 10) || 0) + 1); }
          }
        } else if (kind === 'message') {
          this.emit('agent.message', { text: (env.message && env.message.content) || '' });
        } else if (kind === 'reasoning') {
          this.emit('agent.reasoning', { text: (env.reasoning && (env.reasoning.delta || env.reasoning.text)) || '' });
        } else if (kind === 'waiting') {
          var q = (env.ended && env.ended.question) || '';
          this.emit('system.notice', { text: 'Waiting on you: ' + q });
          // Live decisions inbox (greenhouse decisions/0196): a parked question shows up without a reload —
          // the nav badge ticks up and a card is prepended. The full card (goal/facts) lands on next load.
          if (this.addDecision) { this.addDecision(q); }
        }
      },
      panel: function (id) {
        var p = document.querySelector('[data-panel="' + id + '"]');
        return p ? p.querySelector('[data-panel-body]') : null;
      }
    };
  })();
</script>
HTML;
    }

    /**
     * Connect the runtime to the Mercure hub when one is wired; otherwise report an offline status.
     *
     * Both branches wait for `DOMContentLoaded`, and that wait is load-bearing (greenhouse decisions/0211).
     * This is an inline script at the end of `<body>`, so it runs DURING parsing — before every deferred
     * script, which is now where the component modules live. `desktop-gate.js` subscribes to
     * `gate.opened` and `desktop-activity.js` to `onAny()`; both used to be registered by the page's own
     * inline script, which ran first. Opening the stream here would put a window between the first
     * message and the handlers that must see it — and a fact already queued at the hub arrives inside it.
     * Every deferred script has executed by `DOMContentLoaded`, so subscribing before connecting is the
     * whole point of the listener.
     */
    private function connectScript(string $agentSid = ''): string
    {
        if ($this->mercure === null) {
            return "<script>document.addEventListener('DOMContentLoaded', function () { window.MilpaShell.status('offline'); });</script>";
        }

        // Subscribe to TWO exact topics on one connection: the shell topic (desktop ShellEvents) and this
        // session's stream (a governed turn's session.* events, greenhouse decisions/0190). Exact topics —
        // not a URI template — so the hub authorizes and delivers them without matching ambiguity.
        $url = json_encode(
            $this->mercure->publicUrl . '?topic=' . rawurlencode($this->mercure->topic)
            . '&topic=' . rawurlencode(\Milpa\DesktopApp\Live\MercureConfig::sessionTopic($agentSid)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return <<<HTML
<script>
  // Deferred to DOMContentLoaded ON PURPOSE: every component module is a deferred script, and the gate
  // and the activity stream subscribe to this bus from theirs. Connecting first would drop whatever the
  // hub had already queued (greenhouse decisions/0211).
  document.addEventListener('DOMContentLoaded', function () {
    var es = new EventSource({$url}, { withCredentials: true });
    es.onopen = function () { window.MilpaShell.status('live'); };
    es.onerror = function () { window.MilpaShell.status('offline'); };
    es.onmessage = function (e) {
      var env; try { env = JSON.parse(e.data); } catch (err) { return; }
      // Two shapes on one connection: a desktop ShellEvent carries `event`; a governed turn's session
      // projection carries `kind` (activity/message/waiting). Map the session ones to the shell's UI.
      if (env && typeof env.event === 'string') { window.MilpaShell.emit(env.event, env.data); return; }
      if (env && typeof env.kind === 'string') { window.MilpaShell.session(env); }
    };
  });
</script>
HTML;
    }

    private function template(): string
    {
        return <<<'HTML'
<!doctype html>
<html data-theme="dark" lang="en"<!--EMBED-->>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Milpa Desktop</title>
<link rel="stylesheet" href="/desktop/assets/tokens.css">
<link rel="stylesheet" href="/desktop/assets/bundle.css">
<style>
  body { margin: 0; background: var(--bg); color: var(--text); font-family: var(--font-body); }
  .app { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
  .chrome { flex: none; display: flex; align-items: center; gap: var(--space-3); height: 46px; padding: 0 var(--space-4); border-bottom: 1px solid var(--border-subtle); background: var(--surface); }
  .lights { display: flex; gap: 8px; }
  .lights span { width: 12px; height: 12px; border-radius: 99px; }
  .statusbar { flex: none; display: flex; align-items: center; gap: var(--space-5); height: 40px; padding: 0 var(--space-4); border-top: 1px solid var(--border-subtle); background: var(--surface); font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); }
  /* The `hidden` attribute must win over an element's own inline `display:` — the auth overlay, the
     composer's floating panels and the replay views all carry both, so without !important they can
     never actually hide (the inline display outranks a plain [hidden] rule). This is what makes
     "Open workspace" reveal the dashboard and the composer panels close as you type. */
  [hidden] { display: none !important; }
  .tabpane[hidden] { display: none; }
  /* Embed mode (greenhouse decisions/0210): the Desktop as the admin's guest. The chrome FOLDS — the window
     strip, the sidebar, the topbar and the status bar are not visible and the main takes the whole grid — but
     the DOM stays: every id the shell script looks up at boot is still in the document. The session strip
     above the conversation is what keeps the sessions reachable while the sidebar is folded. */
  html[data-embed="1"] .chrome, html[data-embed="1"] .statusbar, html[data-embed="1"] .mui-sidebar, html[data-embed="1"] .mui-topbar { display: none !important; }
  html[data-embed="1"] .mui-shell { grid-template-columns: minmax(0, 1fr) !important; grid-template-rows: minmax(0, 1fr) !important; }
  html[data-embed="1"] .mui-shell__main { grid-column: 1 !important; grid-row: 1 !important; }
  ul.feed { list-style: none; margin: 0; padding: 0; font: var(--text-xs)/1.5 var(--font-mono); overflow: auto; }
  ul.feed li { padding: var(--space-2) var(--space-3); border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--border-subtle); margin: var(--space-2) 0; word-break: break-word; }
  .mui-empty { color: var(--text-muted); font-size: var(--text-sm); }
  .panel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: var(--space-4); }
  /* Capabilities catalogue: installed vs available, the same list the agent reads (greenhouse decisions/0193). */
  .cap-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6); align-items: start; }
  @media (max-width: 760px) { .cap-grid { grid-template-columns: 1fr; } }
  .cap-col__head { margin: 0 0 var(--space-3); font-family: var(--font-mono); font-size: var(--text-2xs); letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); }
  .cap-stack { display: flex; flex-direction: column; gap: var(--space-3); }
  .cap-card { border: 1px solid var(--border-subtle); border-radius: var(--radius-md); background: var(--surface); padding: var(--space-3) var(--space-4); }
  .cap-card__head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
  .cap-card__name { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text); word-break: break-all; }
  .cap-card__desc { margin: var(--space-2) 0 0; font-size: var(--text-xs); color: var(--text-secondary); }
  .cap-card__sub { margin: var(--space-1) 0 0; font-size: var(--text-2xs); color: var(--text-muted); }
  .cap-confirm { margin-top: var(--space-3); padding: var(--space-3); border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg); }
  .cap-confirm__cmd { font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--accent-text); word-break: break-all; }
  .cap-confirm__row { display: flex; gap: var(--space-2); margin-top: var(--space-3); }
  .cap-msg { margin-top: var(--space-4); font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-secondary); }
  /* Decisions inbox: the questions agents parked, across all sessions (greenhouse decisions/0195). */
  #milpa-decisions-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-4); max-width: 72ch; }
  .decision-card { border: 1px solid var(--warning-border, var(--border)); border-radius: var(--radius-md); background: var(--surface); padding: var(--space-4) var(--space-5); }
  .decision-card__goal { margin: 0 0 var(--space-2); font-family: var(--font-mono); font-size: var(--text-2xs); letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
  .decision-card__q { margin: 0; font-size: var(--text-sm); line-height: var(--leading-relaxed); color: var(--text); text-wrap: pretty; }
  .decision-card__facts { margin: var(--space-2) 0 0; font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-secondary); }
  .decision-card__open { margin-top: var(--space-3); }
  /* Interrupted-run notice (greenhouse decisions/0196): a prior run left mid-turn, reported not auto-resumed. */
  .milpa-interrupted { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-3) var(--space-4); border: 1px solid var(--warning-border, var(--border)); border-radius: var(--radius-md); background: var(--warning-bg, var(--surface)); font-size: var(--text-sm); color: var(--text-secondary); }
  .milpa-interrupted__mark { color: var(--warning); }
  /* Skills: what the agent carries — each guides judgment, not a tool that runs (greenhouse decisions/0197). */
  .skill-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-3); max-width: 72ch; }
  .skill-card { border: 1px solid var(--border-subtle); border-radius: var(--radius-md); background: var(--surface); padding: var(--space-3) var(--space-4); }
  .skill-card__head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
  .skill-card__name { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text); }
  .skill-card__desc { margin: var(--space-2) 0 0; font-size: var(--text-sm); line-height: var(--leading-relaxed); color: var(--text-secondary); text-wrap: pretty; }
  .role-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-3); max-width: 72ch; }
  .role-card { border: 1px solid var(--border-subtle); border-radius: var(--radius-md); background: var(--surface); padding: var(--space-3) var(--space-4); }
  .role-card__name { margin: 0; font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text); }
  .role-card__line { margin: var(--space-2) 0 0; font-size: var(--text-xs); color: var(--text-secondary); display: flex; flex-wrap: wrap; gap: var(--space-2); align-items: baseline; }
  .role-card__key { font-family: var(--font-mono); font-size: var(--text-2xs); letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
  /* Declared-screen preview chips (greenhouse decisions/0197). */
  .screen-chips { display: inline-flex; flex-wrap: wrap; gap: var(--space-2); }
  .screen-chip { display: inline-flex; align-items: baseline; gap: var(--space-2); padding: 4px 10px; border: 1px solid var(--border); border-radius: var(--radius-full); background: var(--surface); color: var(--text); cursor: pointer; font: inherit; }
  .screen-chip:hover { border-color: var(--accent); }
  .screen-chip__name { font-family: var(--font-mono); font-size: var(--text-xs); }
  .screen-chip__type { font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); }
  .milpa-mode-opt[aria-current="true"] { background: var(--accent-subtle); color: var(--accent-text); }
  /* Command completion (greenhouse decisions/0202): the list the house serves, opened above the composer while
     a `/name` is being typed. Its visibility is CSS state on `data-open`; the shell's delegated handler flips it. */
  .milpa-cmds { position: absolute; left: 0; bottom: calc(100% + 8px); z-index: 60; min-width: 24rem; max-width: 100%; max-height: 16rem; overflow: auto; display: flex; flex-direction: column; gap: 2px; padding: 4px; background: var(--surface-raised); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); }
  .milpa-cmds[data-open="0"] { display: none; }
  .milpa-cmd { display: grid; grid-template-columns: auto 1fr; column-gap: var(--space-3); align-items: baseline; text-align: start; padding: 6px 10px; border: 0; border-radius: var(--radius-sm); background: none; color: var(--text); cursor: pointer; font: inherit; }
  .milpa-cmd:hover, .milpa-cmd[aria-selected="true"] { background: var(--accent-subtle); color: var(--accent-text); }
  .milpa-cmd__name { font-family: var(--font-mono); font-size: var(--text-xs); }
  .milpa-cmd__desc { font-size: var(--text-xs); color: var(--text-secondary); }
  .milpa-cmd__usage { grid-column: 2; font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); }
  /* The focus ring belongs to the composer BOX, not the bare textarea — so the accent border sits out at
     the rounded container with its padding as breathing room, instead of hugging the typed text. */
  .milpa-composer-box:focus-within { border-color: var(--accent) !important; box-shadow: 0 0 0 3px var(--accent-subtle); }
  /* One border, one authority: the milpa/live field renders its own border by default, but here it lives
     INSIDE the composer box — a second border reads as a second authority (Rod's doctrine). The box owns
     the frame and the focus ring; the field is seamless — no border, no background, no ring of its own. */
  .milpa-composer-box .mui-field { margin: 0; }
  .milpa-composer-box .mui-textarea,
  .milpa-composer-box .mui-textarea:focus,
  .milpa-composer-box .mui-textarea:focus-visible {
    border: 0; outline: 0; background: transparent; box-shadow: none; padding: 0;
  }
  /* No visible scrollbars anywhere — scrolling still works. */
  * { scrollbar-width: none; -ms-overflow-style: none; }
  *::-webkit-scrollbar { width: 0; height: 0; display: none; }
  @media (prefers-reduced-motion: reduce) { * { animation-duration: .001ms !important; transition-duration: .001ms !important; } }
</style>
<!-- ONE runtime per page (greenhouse decisions/0211): every stylesheet the compiled components declared,
     the boot the server issued, the two runtimes, the Desktop's client modules and Alpine — emitted by
     LiveBoot::html(), each once, each deferred. The shell hand-writes no runtime script tag. -->
<!--LIVERUNTIME-->
</head>
<body>
<!--RUNTIME-->
<div class="app">

  <div class="chrome">
    <span class="lights"><span style="background:var(--danger)"></span><span style="background:var(--warning)"></span><span style="background:var(--success)"></span></span>
    <input id="milpa-search" type="search" placeholder="Search sessions…" aria-label="Search sessions" autocomplete="off" class="mui-input mui-input--sm" style="max-width:280px;margin-inline-start:var(--space-4);font-family:var(--font-mono);font-size:var(--text-xs)">
    <span style="margin-inline:auto;font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">served by Milpa · one origin</span>
    <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm" id="milpa-auth-open">Open workspace</button>
    <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm" id="milpa-theme" aria-label="toggle theme">◐ Theme</button>
  </div>

  <div class="mui-shell" style="flex:1;min-height:0;--_sidebar-w:17.5rem;overflow:hidden;display:grid;grid-template-columns:var(--_sidebar-w) minmax(0,1fr);grid-template-rows:auto minmax(0,1fr)">
    <!--SIDEBAR-->

    <!--TOPBAR-->

    <main class="mui-shell__main mui-shell__main--wide" style="grid-row:2;grid-column:2;min-height:0;padding:0;display:flex;flex-direction:column;overflow:hidden">
      <div class="view" data-view="session" x-data style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden">
      <!-- Embed mode only (greenhouse decisions/0210): the session strip — the sidebar's reach in one row. -->
      <!--SESSIONSTRIP-->
      <!--TABS-->

      <div style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8)">

        <!-- The conversation is a Milpa Component that COMPOSES the message components (greenhouse
             decisions/0191): user/agent/thinking/tool/task/system messages are cloned in from their own
             components' prototypes, and the consent gate lives here too. Its empty state hides once a
             message lands. -->
        <section class="tabpane milpa-chat" data-pane="chat" id="milpa-chat" data-milpa-component="desktop-conversation" data-milpa-component-id="conversation" :hidden="$store.milpa['desktop.tab'] !== 'chat'">
          <!--INTERRUPTED-->
          <!--CONVERSATION-->

          <!-- The consent gate is the `desktop-gate` component (greenhouse decisions/0189): hidden until an
               agent parks a question; its visibility is the shared `desktop.gate.open` signal. -->
          <!--GATE-->
        </section>

        <section class="tabpane" data-pane="work" hidden :hidden="$store.milpa['desktop.tab'] !== 'work'">
          <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">The session's work board — todo items by status.</p>
          <!--WORK-->
        </section>

        <section class="tabpane" data-pane="activity" hidden :hidden="$store.milpa['desktop.tab'] !== 'activity'" style="display:grid;grid-template-columns:1fr 20rem;gap:var(--space-6);align-items:start">
          <!--ACTIVITY-->
        </section>

        <section class="tabpane" data-pane="context" hidden :hidden="$store.milpa['desktop.tab'] !== 'context'">
          <!-- The panel grid is the `desktop-context` component; plugins contribute panels via addPanel. -->
          <!--CONTEXT-->
        </section>

      </div>
      <!-- The composer is docked below the scroll, sticky at the bottom: messages flow above it and it
           stays put. Shown only on the Conversation tab. -->
      <div id="milpa-composer-dock" :hidden="$store.milpa['desktop.tab'] !== 'chat'" style="flex:none;padding:var(--space-3) var(--space-8) var(--space-5);border-top:1px solid var(--border-subtle);background:var(--bg)"><!--COMPOSER--></div>
      </div><!-- /view session -->

      <!-- The thinking message component's prototype (greenhouse decisions/0191): the conversation clones this
           per turn — Alpine hydrates each clone — and feeds it the reasoning by `thinking:delta`/`thinking:done`
           events. A plugin extends every thinking block by hooking the component's render events, once, here. -->
      <template id="milpa-thinking-proto"><!--THINKING--></template>

      <!-- The agent-message component's prototype (greenhouse decisions/0191): cloned per answer, filled into
           its body, its foot tools (copy, regenerate) acting through the conversation's delegated handler. -->
      <template id="milpa-agent-msg-proto"><!--AGENTMSG--></template>

      <!-- The plainer message components' prototypes (greenhouse decisions/0191): the conversation clones the
           one for each message kind and fills its data regions. Each a declared, event-emitting component. -->
      <template id="milpa-user-msg-proto"><!--USERMSG--></template>
      <template id="milpa-tool-msg-proto"><!--TOOLMSG--></template>
      <template id="milpa-task-msg-proto"><!--TASKMSG--></template>
      <template id="milpa-system-msg-proto"><!--SYSMSG--></template>
      <template id="milpa-result-msg-proto"><!--RESULTMSG--></template>

      <!-- The Settings screen is the `desktop-settings` component now (greenhouse decisions/0211): the
           four cards, the Save/Discard row and the save badge all come from its renderer, its look from
           desktop-settings.css and its behaviour from desktop-settings.js. -->
      <!--SETTINGS-->

      <div class="view" data-view="capabilities" hidden style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8)">
        <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">What this app can do today, and what it could — the same catalogue the agent reads.</p>
        <div id="milpa-capabilities"><!--CAPABILITIES--></div>
      </div>

      <div class="view" data-view="skills" hidden style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8)">
        <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">The skills the agent carries — each guides judgment, it is not a tool that runs. The same list the agent reaches for.</p>
        <div id="milpa-skills"><!--SKILLS--></div>
        <p class="agent-section__head" style="margin:var(--space-8) 0 var(--space-4);font-family:var(--font-mono);font-size:var(--text-2xs);letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted)">Specialist roles</p>
        <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">Specialist agents this app declares — each a named authority with the skills it preloads and the tools it is denied.</p>
        <div id="milpa-roles"><!--ROLES--></div>
      </div>

      <div class="view" data-view="preview" hidden style="flex:1;min-height:0;overflow:hidden;padding:var(--space-6) var(--space-8);display:flex;flex-direction:column;gap:var(--space-4)">
        <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0">See what the agent is building — a screen it declared, rendered live and hosted here. "How does it look?", answered.</p>
        <div class="mui-cluster mui-cluster--sm" style="align-items:center;gap:var(--space-3);flex:none">
          <input class="mui-input mui-input--sm" id="milpa-preview-name" placeholder="screen name" style="max-width:26ch;font-family:var(--font-mono);font-size:var(--text-xs)">
          <button type="button" class="mui-btn mui-btn--primary mui-btn--sm" id="milpa-preview-go" data-live-route="<!--LIVEROUTE-->">Preview</button>
          <span id="milpa-screens"><!--SCREENS--></span>
        </div>
        <iframe id="milpa-preview-frame" title="Live screen preview" style="flex:1;min-height:0;width:100%;border:1px solid var(--border-subtle);border-radius:var(--radius-md);background:var(--surface)"></iframe>
      </div>

      <div class="view" data-view="decisions" hidden style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8)">
        <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">Decisions an agent has parked for you, across every session — durable questions, not modals. Open the session to approve or refuse, with your passkey, in this origin.</p>
        <!--DECISIONS-->
      </div>

    </main>
  </div>

  <div class="statusbar">
    <span id="milpa-conn" style="color:var(--text-muted)">○ connecting…</span>
    <span>qwen3.8-27b · local model</span>
    <span><!--STATUS--></span>
    <span style="margin-inline-start:auto">m4-core local-agent · v0.1.0</span>
  </div>
</div>

<!-- The entry overlay — «Open workspace» — is the `desktop-auth` component now (greenhouse
     decisions/0211): its markup comes from a renderer, its look from desktop-auth.css and its ceremony
     from desktop-auth.js. Its visibility is the shared `desktop.auth.open` signal. -->
<!--AUTH-->

<!-- The composer's commands (greenhouse decisions/0202): the house's own plus every user-invocable skill, the
     same list the completion popup renders. Served BEFORE the shell script, which reads it once on boot. -->
<script id="milpa-commands" type="application/json"><!--COMMANDS--></script>
<!-- The Desktop's copy in the declared locale (greenhouse decisions/0209): what the guard says when the door answers. -->
<script id="milpa-desktop-i18n" type="application/json"><!--I18N--></script>
<script>
  (function () {
    // The shared guard is its OWN runtime module now (greenhouse decisions/0211, phase A4): the Desktop's copy
    // (#milpa-desktop-i18n), the fetch discipline every call passes through (401 → sign in and come back,
    // 403 → told once, 428 → the capabilities flow, anything else → rejected with its status), the
    // `desktop.notice` signal and the ONE document-level click listener all live in
    // /desktop/assets/c/desktop-guard.js and hang off `MilpaLive.desktop` — so this script and every component
    // module that follows reach the SAME guard instead of each carrying a copy of it. LiveBoot emits it
    // deferred, after the runtime, so it is resolved ON USE and never at parse time.
    function desk() { return (window.MilpaLive && window.MilpaLive.desktop) || null; }
    function tr(key, arg) { var d = desk(); return d ? d.tr(key, arg) : key; }
    // Fail CLOSED: with no guard loaded a call is refused, never passed through unread.
    function guard(r) { var d = desk(); return d ? d.guarded(r) : Promise.reject(new Error('desktop-guard not loaded')); }
    function guardFlow(r) { var d = desk(); return d ? d.guardedFlow(r) : Promise.reject(new Error('desktop-guard not loaded')); }
    function failed(err, unreachable) { var d = desk(); if (d) { d.failed(err, unreachable); } }
    // notice() no longer reaches into the conversation: it EMITS `desktop.notice` (kind, text) and whoever
    // renders notices consumes it — below, until the Conversation component's own module takes the seam.
    function notice(text) { var d = desk(); if (d) { d.notice('system', text); } }
    // Registering WITH the module has to wait for it: it is deferred, so it runs after this inline script and
    // before DOMContentLoaded. Anything that only CALLS the module resolves it lazily instead.
    function whenGuard(cb) {
      var d = desk();
      if (d) { cb(d); return; }
      document.addEventListener('DOMContentLoaded', function () { var g = desk(); if (g) { cb(g); } });
    }

    // The entry overlay and the Settings screen are DECLARED VIEWS now (greenhouse decisions/0211, phase B):
    // `desktop-auth.js` owns «Open workspace» and the session ceremony, `desktop-settings.js` owns Save,
    // Discard and the `settings.saved` badge — both through this same guard, both reporting only what the
    // door answered. Not a line of either is left here.

    // Capabilities catalogue actions (greenhouse decisions/0193): the click on a named capability shows its
    // exact command, and confirming runs the two-step gate over HTTP; on success the page reloads.
    var capHost = document.getElementById('milpa-capabilities');
    if (capHost) {
      capHost.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-cap-enable]') : null;
        if (!t) { return; }
        var pkg = t.getAttribute('data-cap-enable'), cmd = t.getAttribute('data-cap-cmd') || '';
        var card = t.closest('.cap-card'); if (!card || card.querySelector('.cap-confirm')) { return; }
        var box = document.createElement('div'); box.className = 'cap-confirm';
        box.innerHTML = '<p class="cap-confirm__cmd"></p><div class="cap-confirm__row"><button type="button" class="mui-btn mui-btn--primary mui-btn--sm" data-cap-go>Confirm</button><button type="button" class="mui-btn mui-btn--sm" data-cap-cancel>Cancel</button></div>';
        box.querySelector('.cap-confirm__cmd').textContent = cmd;
        t.disabled = true; card.appendChild(box);
        box.querySelector('[data-cap-cancel]').addEventListener('click', function () { box.remove(); t.disabled = false; });
        box.querySelector('[data-cap-go]').addEventListener('click', function () {
          var go = box.querySelector('[data-cap-go]'); go.disabled = true; go.textContent = 'Working…';
          capEnable(pkg).then(function (r) {
            if (r && r.ok) {
              box.innerHTML = '<p class="cap-confirm__cmd">Done: ' + pkg + '. Reloading…</p>';
              setTimeout(function () { location.reload(); }, 900);
            } else {
              box.innerHTML = '<p class="cap-confirm__cmd">Could not: ' + ((r && r.error) || 'refused') + '</p>';
            }
          });
        });
      });
    }
    function capEnable(pkg) {
      var url = '/capabilities/enable', hdr = { 'Content-Type': 'application/json' }, body = JSON.stringify({ capability: pkg });
      // The first step may answer with the house's confirm gate (428 + the token): that is the flow, not a
      // refusal, so it passes the guard; a door's 401/403 does not (greenhouse decisions/0209).
      return fetch(url, { method: 'POST', headers: hdr, body: body }).then(guardFlow).then(function (r) { return r.json(); }).then(function (a) {
        if (!a || !a.confirm_token) { return (a && a.ok) ? a : { ok: false, error: (a && a.error) || 'no token' }; }
        var h2 = { 'Content-Type': 'application/json', 'Confirm-Token': a.confirm_token };
        return fetch(url, { method: 'POST', headers: h2, body: body }).then(guard).then(function (r2) {
          return r2.json().then(function (d) { return (d && typeof d.ok === 'boolean') ? d : { ok: r2.ok, error: d && d.error }; });
        });
      }).catch(function (err) { return { ok: false, error: (err && err.body && err.body.error) || (err && err.status ? 'HTTP ' + err.status : String(err)) }; });
    }

    // Composer floating panels (wireframe 3a): open on their figures, close as you type. WHICH one is open
    // is the `composer.panel` signal (greenhouse decisions/0211, B4): each chip's @click sets it, both
    // panels bind `:hidden` to it, and typing (below) clears it — no click handler, no `.hidden` poked.
    // The composer's textarea — the milpa/live component's field when wired, else the fallback (same query).
    var composerInput = document.querySelector('#milpa-composer-dock textarea');
    var sendBtn = document.getElementById('milpa-send');
    var chat = document.getElementById('milpa-chat');
    // The send button is enabled only when there is something to send (text today; attachments later) — and
    // that is a SIGNAL, `composer.draft` (greenhouse decisions/0211): the button BINDS its `disabled` to it
    // together with `session.working`, so nothing reaches in and sets the property.
    function refreshSend() {
      setSig('composer.draft', !!composerInput && composerInput.value.trim() !== '');
    }
    // The composer field is a milpa/live component (milpaField): setting its text is an INTERACTION with the
    // component's own data — `reset('')` to clear (value, dirty, touched, error) and `change(text)` to fill a
    // command in — never the synthetic `input` event this used to dispatch. Measured as the least invasive of
    // the three candidates (greenhouse decisions/0211, A3): a StateEffect would spend a server round trip on a
    // local clear, and the synthetic event lied to every other listener on the field, announcing a keystroke
    // that never happened. The element's own value is mirrored in the same tick so the readers that run before
    // Alpine's next flush see the new text.
    function composerData() {
      if (!composerInput || !window.Alpine || typeof window.Alpine.$data !== 'function') { return null; }
      var root = composerInput.closest ? composerInput.closest('[x-data]') : null;
      if (!root) { return null; }
      try {
        var d = window.Alpine.$data(root);
        return (d && typeof d.reset === 'function' && typeof d.change === 'function') ? d : null;
      } catch (e) { return null; }
    }
    function setComposerText(text) {
      if (!composerInput) { return; }
      var d = composerData();
      if (d) { if (text === '') { d.reset(''); } else { d.change(text); } }
      composerInput.value = text;
      refreshSend();
      refreshCount();
      refreshCommandList();
    }
    // The draft's token count lives in the composer footer now (Rod's minimalist UX): live, quiet, empty at
    // zero. A client-side estimate (~4 chars/token — there is no tokenizer in the browser); the real usage is
    // the context figure. The unit is "tokens", not "chars", because that is what the budget is spent in.
    var charCount = document.getElementById('milpa-charcount');
    function refreshCount() {
      if (!charCount) { return; }
      var n = composerInput ? composerInput.value.length : 0;
      var toks = Math.ceil(n / 4);
      charCount.textContent = n > 0 ? ('~' + toks + ' tokens') : '';
    }
    if (composerInput) {
      composerInput.addEventListener('input', function () {
        setSig('composer.panel', '');
        refreshSend();
        refreshCount();
        refreshCommandList();
      });
    }
    // A SAFE markdown renderer for the agent's message (greenhouse decisions/0191, Rod: the answer shows
    // markdown intent). It ESCAPES the model's text first — the model's output is never injected as raw HTML —
    // then applies a small, known-safe subset (fenced/inline code, bold, italic, links with a safe scheme,
    // headings, lists). Every tag it emits is one it wrote; the only text that survives is escaped.
    function renderMarkdown(src) {
      src = String(src == null ? '' : src);
      function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
      var blocks = [];
      src = src.replace(/```[^\n]*\n?([\s\S]*?)```/g, function (_, code) { blocks.push('<pre class="md-pre"><code>' + esc(code.replace(/\n$/, '')) + '</code></pre>'); return '�B' + (blocks.length - 1) + '�'; });
      var out = esc(src);
      out = out.replace(/`([^`\n]+)`/g, '<code class="md-code">$1</code>');
      out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      out = out.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
      out = out.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
      var lines = out.split('\n'), html = '', inList = false;
      for (var i = 0; i < lines.length; i++) {
        var ln = lines[i], h = ln.match(/^(#{1,3})\s+(.*)$/), li = ln.match(/^\s*[-*]\s+(.*)$/);
        if (li) { if (!inList) { html += '<ul class="md-ul">'; inList = true; } html += '<li>' + li[1] + '</li>'; continue; }
        if (inList) { html += '</ul>'; inList = false; }
        if (h) { var lvl = h[1].length + 2; html += '<h' + lvl + ' class="md-h">' + h[2] + '</h' + lvl + '>'; continue; }
        if (ln.trim() === '') { continue; }
        html += '<p>' + ln + '</p>';
      }
      if (inList) { html += '</ul>'; }
      html = html.replace(/<p>�B(\d+)�<\/p>/g, function (_, i) { return blocks[i]; });
      html = html.replace(/�B(\d+)�/g, function (_, i) { return blocks[i]; });
      return html;
    }
    // One data region of a cloned message: the region is a DESCENDANT of the clone, or the clone's own
    // root. `querySelector` never returns the node it was called on, and the system notice carries
    // `data-system-body` ON the `.msg` root (MessagePrototypes::system) — so the plain descendant lookup
    // found nothing and every guard notice painted an EMPTY bubble. Asking for it both ways makes a fill
    // independent of where a component (or a plugin that re-rendered it) put its region.
    function region(root, selector) { return root.matches(selector) ? root : root.querySelector(selector); }
    // Every message is a Milpa Component (greenhouse decisions/0191): the conversation CLONES the prototype for
    // its kind and fills the instance's data regions — no more createElement. The backend's stream routes here
    // by event type; the user's own message uses it too. (Running the turn is the agent runtime, decisions/0254.)
    var MSG_PROTOS = {
      agent: { id: 'milpa-agent-msg-proto', fill: function (r, o) { var b = region(r, '[data-agent-body]'); if (b) { b.innerHTML = renderMarkdown(o.text || ''); } } },
      user: { id: 'milpa-user-msg-proto', fill: function (r, o) { var b = region(r, '[data-user-body]'); if (b) { b.textContent = o.text || ''; } } },
      tool: { id: 'milpa-tool-msg-proto', fill: function (r, o) {
        var n = region(r, '[data-tool-name]'); if (n) { n.textContent = o.name || 'tool'; }
        var raw = String(o.result || '');
        var sum = region(r, '[data-tool-summary]'); if (sum) { sum.textContent = toolSummary(raw); }
        var body = region(r, '[data-tool-body]'); if (body) { body.textContent = prettyMaybe(raw); }
      } },
      task: { id: 'milpa-task-msg-proto', fill: function (r, o) { var t = region(r, '[data-task-title]'); if (t) { t.textContent = o.title || ''; } var s = region(r, '[data-task-status]'); if (s) { s.textContent = o.status || 'todo'; } } },
      system: { id: 'milpa-system-msg-proto', fill: function (r, o) { var b = region(r, '[data-system-body]'); if (b) { b.textContent = o.text || ''; } } },
      result: { id: 'milpa-result-msg-proto', fill: function (r, o) {
        var ok = o.verified !== false;
        r.setAttribute('data-verified', ok ? '1' : '0');
        var mark = region(r, '[data-result-mark]'); if (mark) { mark.textContent = ok ? '✓' : '⚠'; }
        var txt = region(r, '[data-result-text]'); if (txt) { txt.textContent = ok ? 'verified' : 'disputed'; }
        // The tooltip carries WHAT the ledger judged — the reasons go here now, not inline (Rod's minimalism).
        var tip = ok
          ? "The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact's latest check is red."
          : ('The ledger disputes this turn — ' + (o.reasons ? o.reasons : 'the completion is not backed by evidence') + '.');
        var te = region(r, '[data-result-tip]'); if (te) { te.textContent = tip; }
        r.setAttribute('aria-label', (ok ? 'Verified. ' : 'Disputed. ') + tip);
      } }
    };
    // A tool result's one-line summary (a count for JSON, a truncation otherwise); the raw sits in the
    // collapsible body below. Milpa Components render the machinery legibly, not as a raw dump (Rod).
    function toolSummary(raw) {
      if (!raw) { return ''; }
      try {
        var j = JSON.parse(raw);
        if (Array.isArray(j)) { return '→ ' + j.length + ' items'; }
        if (j && typeof j === 'object') { return typeof j.ok !== 'undefined' ? ('→ ok · ' + Object.keys(j).length + ' fields') : ('→ ' + Object.keys(j).length + ' fields'); }
      } catch (e) {}
      return '→ ' + (raw.length > 60 ? raw.slice(0, 60) + '…' : raw);
    }
    function prettyMaybe(raw) {
      try { return JSON.stringify(JSON.parse(raw), null, 2); } catch (e) { return raw; }
    }
    function appendMessage(kind, opts) {
      if (!chat) { return; }
      opts = opts || {};
      // A discrete "thinking" message reuses the thinking component (streamed thinking uses appendReasoning).
      if (kind === 'thinking') { appendReasoning(opts.text || ''); endReasoning(); return; }
      var spec = MSG_PROTOS[kind] || MSG_PROTOS.system;
      var proto = document.getElementById(spec.id);
      if (!proto || !('content' in proto)) { return; }
      var frag = proto.content.cloneNode(true);
      var root = frag.querySelector('.msg');
      if (root) { spec.fill(root, opts); }
      chat.appendChild(frag);
      if (root) { root.scrollIntoView({ block: 'end' }); }
      return root;
    }
    // The conversation CONSUMES `desktop.notice` (greenhouse decisions/0211): the guard says what happened,
    // the thread renders it as a system message. This is the seam the Conversation component's own module
    // takes over when the conversation group moves; nothing else couples the guard to the chat.
    whenGuard(function (d) {
      d.onNotice(function (n) { appendMessage('system', { text: (n && n.text) || '' }); });
    });

    // The thinking block is the `desktop-thinking` Milpa Component (greenhouse decisions/0191): the conversation
    // CLONES its server-rendered prototype per turn and feeds THIS instance — the reasoning into its body, the
    // elapsed into its head. Its collapse is the component's own (CSS on `data-open`); one delegated toggle
    // handler (below) flips it. No per-instance imperative wiring, no reliance on Alpine hydrating a clone.
    var reasoningEl = null, reasoningStart = 0;
    function appendReasoning(delta) {
      if (!chat) { return; }
      if (!reasoningEl) {
        var proto = document.getElementById('milpa-thinking-proto');
        if (!proto || !('content' in proto)) { return; }
        var frag = proto.content.cloneNode(true);
        reasoningEl = frag.querySelector('.milpa-think');
        reasoningStart = Date.now();
        chat.appendChild(frag);
      }
      if (reasoningEl) {
        var body = reasoningEl.querySelector('[data-thinking-body]');
        if (body) { body.textContent += (delta || ''); }
        reasoningEl.scrollIntoView({ block: 'end' });
      }
    }
    function endReasoning() {
      if (!reasoningEl) { return; }
      var secs = Math.max(1, Math.round((Date.now() - reasoningStart) / 1000));
      // Replace only the LABEL words — the animated spark/dots are the component's own and must survive.
      var label = reasoningEl.querySelector('[data-thinking-label]');
      if (label) { label.textContent = 'thought for ' + secs + 's'; }
      else { var head = reasoningEl.querySelector('[data-thinking-head]'); if (head) { head.textContent = '◈ thought for ' + secs + 's'; } }
      reasoningEl.setAttribute('data-thinking-active', '0'); // stop the pulse: the reasoning is done
      reasoningEl.setAttribute('data-open', '0');
      reasoningEl = null;
    }
    // The closure verdict RIDES the last agent answer's tool row (Rod's ask — saves a whole line). Returns
    // false when there's no answer to ride, so the caller can fall back to the standalone result-claim line.
    function markAgentVerdict(verified, reasons) {
      if (!chat) { return false; }
      var msgs = chat.querySelectorAll('.msg--agent');
      var last = msgs.length ? msgs[msgs.length - 1] : null;
      var slot = last ? last.querySelector('[data-agent-verdict]') : null;
      if (!slot) { return false; }
      var ok = verified !== false;
      slot.setAttribute('data-verified', ok ? '1' : '0');
      var mark = slot.querySelector('[data-verdict-mark]'); if (mark) { mark.textContent = ok ? '✓' : '⚠'; }
      var lbl = slot.querySelector('[data-verdict-label]'); if (lbl) { lbl.textContent = ok ? 'verified' : 'disputed'; }
      var tip = ok
        ? "The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact's latest check is red."
        : ('The ledger disputes this turn — ' + (reasons ? reasons : 'the completion is not backed by evidence') + '.');
      var te = slot.querySelector('[data-verdict-tip]'); if (te) { te.textContent = tip; }
      slot.setAttribute('aria-label', (ok ? 'Verified. ' : 'Disputed. ') + tip);
      slot.hidden = false;
      return true;
    }
    // One set of delegated handlers for every message component, now and future (greenhouse decisions/0191):
    // the thinking block's toggle, and the agent message's foot tools (copy the answer, regenerate it).
    if (chat) {
      chat.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }
        var toggle = e.target.closest('[data-thinking-toggle]');
        if (toggle) {
          var block = toggle.closest('.milpa-think');
          if (block) { block.setAttribute('data-open', block.getAttribute('data-open') === '1' ? '0' : '1'); }
          return;
        }
        var toolToggle = e.target.closest('[data-tool-toggle]');
        if (toolToggle) {
          var tool = toolToggle.closest('.msg--tool');
          if (tool) { tool.setAttribute('data-open', tool.getAttribute('data-open') === '1' ? '0' : '1'); }
          return;
        }
        var copyBtn = e.target.closest('[data-agent-copy]');
        if (copyBtn) {
          var msg = copyBtn.closest('.msg--agent');
          var bodyEl = msg && msg.querySelector('[data-agent-body]');
          var textToCopy = bodyEl ? bodyEl.textContent : '';
          if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(textToCopy).catch(function () {}); }
          copyBtn.classList.add('is-done');
          setTimeout(function () { copyBtn.classList.remove('is-done'); }, 1200);
          return;
        }
        var regenBtn = e.target.closest('[data-agent-regenerate]');
        if (regenBtn) {
          if (lastPrompt) { runTurn(lastPrompt); }
          return;
        }
      });
    }

    // The agent session this Desktop drives (greenhouse decisions/0190): the server minted it, set it in a
    // cookie, and scoped the hub JWT + the EventSource to its exact stream topic — so a governed turn's
    // session.* events reach this shell live.
    var agentSession = '<!--AGENTSID-->';

    // The last prompt sent — so the agent message's Regenerate tool can re-run the same turn.
    var lastPrompt = '';
    // Start a governed turn over the HTTP surface (greenhouse decisions/0190). The working/idle badge and the
    // reasoning stream arrive live over the hub on this session's topic; the final answer comes back here.
    // The mode is the chip's VALUE (greenhouse decisions/0202) — `ask` keeps mutating tools behind their gate;
    // `auto` only skips asking for the reversible; a signature or third-party egress asks in every mode.
    function runTurn(text) {
      lastPrompt = text;
      fetch('/agent', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: text, session: agentSession, mode: currentMode() })
      }).then(guard).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.ok && res.answer) { appendMessage('agent', { text: res.answer }); }
        else if (res && res.paused) { appendMessage('system', { text: res.hint || 'The agent is waiting on your decision.' }); }
        else if (res && res.error) { appendMessage('system', { text: res.error }); }
        // The closure verdict as a result-claim message (greenhouse decisions/0191, evidence/0442): the ledger
        // either backs the answer or disputes it. Only show it when the house actually judged the turn.
        if (res && res.closure) {
          var v = res.closure.verified !== false, why = (res.closure.reasons || []).join('; ');
          // Primary: ride the answer's tool row (saves a line). Fallback: the standalone result-claim message.
          if (!markAgentVerdict(v, why)) { appendMessage('result', { verified: v, reasons: why }); }
        }
        // Update the shared counters from what the turn reported (greenhouse decisions/0191): one truth, and
        // every place that shows turns/steps/tools/tokens — the composer chips, the status bar, the panels —
        // is a projection of these signals, so they all move at once.
        if (res && res.ok) { updateCounters(res); }
      }).catch(function (err) { failed(err, tr('guard.unreachable')); });
    }
    // The counters as signals, updated from the turn and the stream — the single source projected everywhere.
    function sig(key) { return (window.MilpaLive && MilpaLive.signal) ? (MilpaLive.signal(key) || 0) : 0; }
    // The same read WITHOUT the numeric coercion — a boolean signal (`session.working`) is not a counter.
    function rawSig(key) { return (window.MilpaLive && MilpaLive.signal) ? MilpaLive.signal(key) : null; }
    function setSig(key, val) { if (window.MilpaLive && MilpaLive.signal) { MilpaLive.signal(key, val); } }
    function kfmt(n) { return (n / 1000).toFixed(2) + 'K'; }
    function updateCounters(res) {
      setSig('session.turns', (parseInt(sig('session.turns'), 10) || 0) + 1);
      if (typeof res.steps === 'number') { setSig('session.steps', (parseInt(sig('session.steps'), 10) || 0) + res.steps); }
      // The REAL token cost, from the provider's own numbers the turn reported (greenhouse decisions/0192) —
      // a counted figure, not an estimate. `tokens` is the session's cumulative total; `contextTokens` is what the last call
      // put in the window. Absent when the provider never said (the op omits it), so the seed stands rather
      // than a fabricated zero — the house's own doctrine (SessionEvent::ModelReturned): a token bar that
      // guesses is a fabricated number in a real one's clothes. One truth, projected to the status bar and chip.
      if (typeof res.tokens === 'number') { setSig('session.tokens', kfmt(res.tokens)); }
      if (typeof res.contextTokens === 'number') { setSig('context.used', kfmt(res.contextTokens)); }
    }
    // ── Composer commands (greenhouse decisions/0202) ────────────────────────────────────────────────────
    // The house SERVES the command list (#milpa-commands): its own commands (goal / mode / help) and every
    // user-invocable skill. Each command is a governed OPERATION of the house — the Desktop invents no
    // action; it calls the runtime's http projection of the op with the METHOD the list declares:
    // `agent:goal` → POST /agent/goal, `skill:invoke` → GET /skill/invoke (a read projects as GET; the
    // projector derives the path from the name: `:` → `/`, `_` → `-`). `/mode` writes the chip and the
    // saved setting; the next turn carries the mode through POST /agent — one writer, no second call. The
    // doctrine's boundary holds here: a goal or a mode never pre-consents a signature (requiresConfirmation,
    // the Executable+Privileged ceiling) nor third-party egress — the goal only bounds what the automatic
    // mode may already pre-consent.
    var MODES = ['ask', 'acknowledge', 'auto'];
    var HOUSE_COMMANDS = ['goal', 'mode', 'help'];
    var COMMANDS = (function () {
      var el = document.getElementById('milpa-commands');
      try { var v = el ? JSON.parse(el.textContent || '[]') : []; return Array.isArray(v) ? v : []; } catch (e) { return []; }
    })();
    function commandNamed(name) {
      for (var i = 0; i < COMMANDS.length; i++) { if (COMMANDS[i].name === name) { return COMMANDS[i]; } }
      return null;
    }
    // `/name [args]` — the name is [a-z0-9-]+, the rest (trimmed) is its argument text. Only a REAL command
    // is intercepted: a house command, or a name the served list carries. Anything else is the model's —
    // "/tmp/app.log has errors" is a prompt, not a command — so it returns null and the turn runs unchanged.
    function parseCommand(text) {
      var m = /^\/([a-z0-9-]+)(?:\s+([\s\S]*))?$/.exec(text);
      if (!m || (HOUSE_COMMANDS.indexOf(m[1]) === -1 && !commandNamed(m[1]))) { return null; }
      return { name: m[1], args: (m[2] || '').trim() };
    }
    // Exactly `/name` — no args — that names no command: almost surely a mistyped command, so it is told
    // rather than sent to the model. With args it is a prompt.
    function isBareUnknownCommand(text) { return /^\/[a-z0-9-]+$/.test(text) && !parseCommand(text); }
    // The mode every turn sends is the `composer.mode` signal — the chip's VALUE, never a hardcoded 'ask'.
    // Unset or unknown → ask: the mode that asks is the default.
    function currentMode() {
      var m = (window.MilpaLive && MilpaLive.signal) ? MilpaLive.signal('composer.mode') : null;
      return MODES.indexOf(m) !== -1 ? m : 'ask';
    }
    // Call an operation over its http projection with the method the projection answers to: a mutating op
    // POSTs a JSON body, a read GETs its fields as the query string. The op's OWN answer decides: the
    // projector answers 2xx (201 for a mutation) even when the op refused, so `ok` is the transport's ok AND
    // the body's `ok` not being false. 404/405 mean the app does not expose it (config/http.php); 428 is the
    // house's confirm gate — reported, never confirmed from a command. Never a silent failure.
    function callOp(method, path, body) {
      function read(r) {
        return r.text().then(function (t) {
          var d = null; try { d = JSON.parse(t); } catch (e) {}
          d = (d && typeof d === 'object') ? d : {};
          return { status: r.status, ok: r.ok && d.ok !== false, data: d };
        });
      }
      var req;
      if (method === 'GET') {
        var qs = Object.keys(body).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(String(body[k])); }).join('&');
        req = fetch(path + (qs ? '?' + qs : ''), { method: 'GET' });
      } else {
        req = fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      }
      return req.then(guard).then(read).catch(function (err) {
        // The door answered (greenhouse decisions/0209): the status and body survive for opFailure; a refusal
        // guarded() already told is marked, so it is not told twice.
        if (err && err.status) { return { status: err.status, ok: false, data: err.body || {}, told: !!err.told }; }
        return { status: 0, ok: false, data: { error: String(err) } };
      });
    }
    // A failed call as one legible line: the op, what happened, and what to do about it.
    function opFailure(op, res) {
      var d = res.data || {}, err = d.error || (d.errors ? JSON.stringify(d.errors) : '');
      if (res.status >= 200 && res.status < 300) { return op + ' refused — ' + (err || 'no reason given'); }
      var hint = (res.status === 404 || res.status === 405) ? ('the app does not expose ' + op + ' over HTTP — expose the operation in config/http.php')
        : res.status === 428 ? (op + ' asks for confirmation — the house\'s gate stands; a command does not confirm it')
        : res.status === 0 ? 'the operation could not be reached'
        : 'the operation failed';
      return op + ' → HTTP ' + res.status + ' — ' + hint + (err ? ' (' + err + ')' : '');
    }
    function runCommand(cmd) {
      if (cmd.name === 'help') {
        COMMANDS.forEach(function (c) { notice(c.usage + ' — ' + c.description); });
        return;
      }
      if (cmd.name === 'goal') {
        // agent:goal — set (`goal`), clear (`clear`), or show (neither) the session's standing goal. The
        // notice reads the RESPONSE (`goal`, `changed`), never the request: what the session holds now.
        var body = { session: agentSession };
        if (cmd.args === 'clear') { body.clear = true; } else if (cmd.args !== '') { body.goal = cmd.args; }
        callOp('POST', '/agent/goal', body).then(function (res) {
          if (!res.ok) { if (!res.told) { notice(opFailure('agent:goal', res)); } return; }
          var d = res.data, goal = typeof d.goal === 'string' ? d.goal : '';
          if (goal === '') { notice(d.changed === true ? 'goal cleared' : 'no standing goal — /goal <text> sets one'); return; }
          notice((d.changed === false ? 'goal unchanged: ' : 'goal set: ') + goal);
        });
        return;
      }
      if (cmd.name === 'mode') {
        var key = cmd.args.toLowerCase();
        if (MODES.indexOf(key) === -1) { notice('usage: /mode ask|acknowledge|auto'); return; }
        // The chip and the saved setting change now; the mode reaches the session with the next turn, which
        // sends it (POST /agent carries `mode`). One writer — no separate agent:mode call.
        applyMode(key);
        notice('mode ' + key + ' — applies from the next turn' + (key === 'auto' ? ' (a signature or third-party egress still asks)' : ''));
        return;
      }
      var skill = commandNamed(cmd.name);
      if (skill && skill.kind === 'skill') {
        // skill:invoke — the human's path to a user-invocable skill, a read projected as GET. Its `body` is
        // already the wrapped <skill_content> form: it enters the turn AS-IS, the args after it.
        callOp('GET', '/skill/invoke', { name: skill.name }).then(function (res) {
          var d = res.data;
          if (!res.ok || typeof d.body !== 'string') { if (!res.told) { notice(opFailure('skill:invoke', res)); } return; }
          runTurn(d.body + (cmd.args !== '' ? '\n\n' + cmd.args : ''));
        });
      }
    }
    // The completion popup (#milpa-command-list, a pure CommandListView): opens while the field holds only a
    // command name being typed (`/go…`), lists the commands that start with it, and fills the name on click or
    // Enter. CSS state on `data-open` + one delegated handler — no per-instance x-data. The field announces
    // the popup it controls and the option it highlights (aria-controls / aria-activedescendant).
    var cmdList = document.getElementById('milpa-command-list');
    if (cmdList && composerInput) { composerInput.setAttribute('aria-controls', 'milpa-command-list'); }
    function cmdOptions() { return cmdList ? Array.prototype.slice.call(cmdList.querySelectorAll('.milpa-cmd')) : []; }
    function cmdVisible() { return cmdOptions().filter(function (o) { return !o.hidden; }); }
    function cmdSelect(opt) {
      cmdOptions().forEach(function (o) { o.setAttribute('aria-selected', o === opt ? 'true' : 'false'); });
      if (composerInput) {
        if (opt && opt.id) { composerInput.setAttribute('aria-activedescendant', opt.id); } else { composerInput.removeAttribute('aria-activedescendant'); }
      }
    }
    function cmdOpen() { return !!cmdList && cmdList.getAttribute('data-open') === '1'; }
    function cmdHide() {
      if (cmdList) { cmdList.setAttribute('data-open', '0'); }
      if (composerInput) { composerInput.removeAttribute('aria-activedescendant'); }
    }
    function refreshCommandList() {
      if (!cmdList || !composerInput) { return; }
      var m = /^\/([a-z0-9-]*)$/.exec(composerInput.value);
      if (!m) { cmdHide(); return; }
      var shown = [];
      cmdOptions().forEach(function (o) {
        var hit = (o.getAttribute('data-command') || '').indexOf(m[1]) === 0;
        o.hidden = !hit;
        if (hit) { shown.push(o); }
      });
      cmdList.setAttribute('data-open', shown.length ? '1' : '0');
      cmdSelect(shown[0] || null);
    }
    function fillCommand(name) {
      if (!composerInput) { return; }
      setComposerText('/' + name + ' ');
      cmdHide();
      composerInput.focus();
    }
    if (cmdList) {
      cmdList.addEventListener('mousedown', function (e) { e.preventDefault(); }); // the field keeps its focus
      cmdList.addEventListener('click', function (e) {
        var opt = e.target.closest ? e.target.closest('.milpa-cmd') : null;
        if (opt) { fillCommand(opt.getAttribute('data-command') || ''); }
      });
    }
    // Keys the open popup owns: Escape hides, the arrows move, Enter/Tab fill the selected name — unless the
    // typed name already IS that command, in which case Enter sends it. False → the composer handles the key.
    function commandListHandlesKey(e) {
      if (!cmdOpen()) { return false; }
      var shown = cmdVisible(), idx = -1;
      shown.forEach(function (o, i) { if (o.getAttribute('aria-selected') === 'true') { idx = i; } });
      if (e.key === 'Escape') { e.preventDefault(); cmdHide(); return true; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (shown.length) { cmdSelect(shown[(idx + (e.key === 'ArrowDown' ? 1 : shown.length - 1)) % shown.length]); }
        return true;
      }
      if ((e.key === 'Enter' && !e.shiftKey) || (e.key === 'Tab' && !e.shiftKey)) {
        var pick = shown[idx] || shown[0];
        var name = pick ? (pick.getAttribute('data-command') || '') : '';
        if (e.key === 'Enter' && composerInput && composerInput.value === '/' + name) { cmdHide(); return false; }
        e.preventDefault();
        if (pick) { fillCommand(name); }
        return true;
      }
      return false;
    }
    function send() {
      if (!composerInput) { return; }
      var text = composerInput.value.trim();
      if (text === '') { return; }
      appendMessage('user', { text: text });
      // Clear the field THROUGH its component (milpaField.reset), not with a synthetic input event.
      setComposerText('');
      cmdHide();
      composerInput.focus();
      // A slash command is the house's, not the model's (greenhouse decisions/0202): parsed here and run as
      // the operation it names; only a skill's body reaches the turn. A bare `/name` that names nothing is
      // told; anything else that is not a real command is a prompt and reaches the model unchanged.
      var cmd = parseCommand(text);
      if (cmd) { runCommand(cmd); return; }
      if (isBareUnknownCommand(text)) {
        notice('unknown command ' + text + ' — commands: ' + COMMANDS.map(function (c) { return '/' + c.name; }).join(', '));
        return;
      }
      runTurn(text);
    }
    // While the agent works, the send button becomes Stop and the topbar badge lights. "Working" is the
    // backend's to declare (it arrives as a `session.state` event) — the Desktop reflects and SIGNALS it, it
    // does not run the turn. `session.working` is that signal (greenhouse decisions/0211): the send button
    // (its glyph, its label, its disabled) and the topbar badge (its modifier classes) each BIND to it, so
    // nothing is poked and any surface that must follow the turn binds too. Stop signals an interrupt;
    // honoring it is the agent runtime's (decisions/0254).
    function working() { return rawSig('session.working') === true; }
    function setWorking(on) {
      setSig('session.working', !!on);
      setSig('session.state.label', on ? 'Working' : 'Idle');
    }
    if (sendBtn) {
      sendBtn.addEventListener('click', function () {
        if (working()) { setWorking(false); notice('stop requested'); return; }
        send();
      });
    }
    if (composerInput) {
      // Enter sends; Shift+Enter keeps the newline. An open command popup takes its keys first.
      composerInput.addEventListener('keydown', function (e) {
        if (commandListHandlesKey(e)) { return; }
        if (e.key === 'Enter' && !e.shiftKey && !working()) { e.preventDefault(); send(); }
      });
    }

    // Work board drag-drop: moving a card to another column PERSISTS its new status (0484).
    var board = document.querySelector('.work-board');
    if (board) {
      var dragged = null;
      board.querySelectorAll('article[draggable]').forEach(function (card) {
        card.addEventListener('dragstart', function () { dragged = card; card.style.opacity = '.5'; });
        card.addEventListener('dragend', function () { card.style.opacity = ''; });
      });
      board.querySelectorAll('.work-col').forEach(function (col) {
        col.addEventListener('dragover', function (e) { e.preventDefault(); col.style.background = 'var(--accent-subtle)'; });
        col.addEventListener('dragleave', function () { col.style.background = ''; });
        col.addEventListener('drop', function (e) {
          e.preventDefault();
          col.style.background = '';
          if (!dragged) { return; }
          col.appendChild(dragged);
          fetch('/desktop/work', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              session: board.getAttribute('data-session'),
              index: parseInt(dragged.getAttribute('data-index'), 10),
              status: col.getAttribute('data-status')
            })
          }).then(guard).catch(function (err) { failed(err, tr('guard.unreachable')); });
          dragged = null;
        });
      });
    }

    // The THEME, the TABLIST and the SIDEBAR are declared views now (greenhouse decisions/0211, phase B).
    // The theme is the shared `ui.theme` signal owned by `desktop-topbar.js` (the chrome's toggle and the
    // Settings buttons set the same value); switching a tab is setting `desktop.tab`, which the tablist's
    // own factory in `desktop-tabs.js` does; and `desktop-sidebar.js` owns navigation, the view swap, the
    // session search, «New session» (the embed strip's control runs the SAME one) and the passkey probe.
    // The composer field's server round-trip on blur (validate + cross-component effects) is the
    // framework's own remote runtime (milpaFieldRemote, bound via `remote`); no Desktop JS drives it.

    // Composer mode chip: open a menu to switch ask / acknowledge / auto quickly. The choice is a SIGNAL PAIR
    // (greenhouse decisions/0202): `composer.mode` is the VALUE every turn sends and `composer.mode.label`
    // its label — the chip and the topbar badge read the label, runTurn reads the value. The choice persists
    // SERVER-SIDE only (a partial settings post that merges — greenhouse decisions/0483) and the page seeds
    // both signals from that saved setting on load: one truth, no browser copy to disagree with it. The menu
    // and /mode both go through applyMode, so the two signals never disagree either.
    var modeChip = document.getElementById('milpa-mode-chip');
    var modeMenu = document.getElementById('milpa-mode-menu');
    function modeLabelFor(key) {
      var opt = modeMenu ? modeMenu.querySelector('.milpa-mode-opt[data-mode="' + key + '"]') : null;
      return opt ? (opt.getAttribute('data-label') || key) : key;
    }
    function applyMode(key) {
      setSig('composer.mode', key);
      setSig('composer.mode.label', modeLabelFor(key));
      if (modeMenu) {
        modeMenu.querySelectorAll('.milpa-mode-opt').forEach(function (o) {
          if (o.getAttribute('data-mode') === key) { o.setAttribute('aria-current', 'true'); } else { o.removeAttribute('aria-current'); }
        });
      }
      fetch('/desktop/settings', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mode: key })
      }).then(guard).catch(function (err) { failed(err, tr('guard.unreachable')); });
    }
    if (modeChip && modeMenu) {
      modeChip.addEventListener('click', function (e) {
        e.stopPropagation();
        var willOpen = modeMenu.hidden;
        modeMenu.hidden = !willOpen;
        modeChip.setAttribute('aria-expanded', String(willOpen));
      });
      modeMenu.addEventListener('click', function (e) { e.stopPropagation(); });
      modeMenu.querySelectorAll('.milpa-mode-opt').forEach(function (opt) {
        opt.addEventListener('click', function () {
          applyMode(opt.getAttribute('data-mode'));
          modeMenu.hidden = true;
          modeChip.setAttribute('aria-expanded', 'false');
        });
      });
    }
    // Click-away is ONE signal now (greenhouse decisions/0211): the guard module owns the single
    // document-level listener and bumps `ui.dismiss`; the mode menu and the command popup CONSUME it here
    // instead of each hanging a listener of its own on the document. A surface that stops propagation (the
    // mode chip, the menu itself) is never dismissed by its own click.
    whenGuard(function (d) {
      d.onDismiss(function (e) {
        if (modeChip && modeMenu) {
          modeMenu.hidden = true;
          modeChip.setAttribute('aria-expanded', 'false');
        }
        // A click inside the composer's field is the typist placing the caret — the popup stays.
        if (!(composerInput && e && e.target === composerInput)) { cmdHide(); }
      });
    });
    // Declared-screen preview (greenhouse decisions/0197): point the iframe at the live wire's page route for a
    // screen the agent declared — same-origin, so its own runtime/Alpine boot inside the frame. A chip carries
    // the exact path it is served at; the manual box builds it from the live route + the typed name.
    (function () {
      var frame = document.getElementById('milpa-preview-frame');
      var input = document.getElementById('milpa-preview-name');
      var go = document.getElementById('milpa-preview-go');
      if (!frame || !input || !go) { return; }
      function preview(src) { if (src) { frame.src = src; } }
      go.addEventListener('click', function () {
        var name = (input.value || '').trim();
        if (name === '') { return; }
        var route = go.getAttribute('data-live-route') || '/live';
        preview(route + '/page?component=' + encodeURIComponent(name));
      });
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go.click(); } });
      document.querySelectorAll('#milpa-screens [data-screen-name]').forEach(function (chip) {
        chip.addEventListener('click', function () {
          input.value = chip.getAttribute('data-screen-name') || '';
          preview(chip.getAttribute('data-screen-src') || '');
        });
      });
    })();

    // Connection status → the status bar. The TOP badge is a binding now (greenhouse decisions/0211, B2):
    // the topbar's module owns it through `session.working`, so nothing here reaches for its id.
    var conn = document.getElementById('milpa-conn');
    window.MilpaShell.onStatus(function (state) {
      if (state === 'live') { conn.textContent = '◉ live'; conn.style.color = 'var(--accent-text)'; }
      else { conn.textContent = '○ offline'; conn.style.color = 'var(--text-muted)'; }
    });

    // The conversation stream: the backend's turn arrives as typed events, each rendered in its own voice.
    // Reasoning deltas stream into the live thinking block; the agent's message (or the turn ending) closes it.
    window.MilpaShell.on('agent.reasoning', function (d) { appendReasoning((d && d.text) || ''); });
    window.MilpaShell.on('agent.message', function (d) { endReasoning(); appendMessage('agent', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('agent.thinking', function (d) { appendMessage('thinking', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('tool.call', function (d) { appendMessage('tool', { name: (d && d.name) || 'tool', result: (d && d.result) || '' }); });
    window.MilpaShell.on('task.added', function (d) { appendMessage('task', { title: (d && d.title) || '', status: (d && d.status) || 'todo' }); });
    window.MilpaShell.on('system.notice', function (d) { appendMessage('system', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('session.state', function (d) { if (d && d.state !== 'working') { endReasoning(); } setWorking(d && d.state === 'working'); });

    // The ACTIVITY tab and the CONSENT GATE are declared views now (greenhouse decisions/0211, phase B):
    // `desktop-activity.js` prepends every live fact of the shell bus to its own stream, and
    // `desktop-gate.js` fills the gate from `gate.opened` as component DATA the card binds to — which is
    // also where the bug died: the page's handler ended on `#milpa-decisions-badge`, an element nothing
    // renders, so every parked question threw after painting the card. The decisions count is the
    // sidebar's badge, ticked by MilpaShell.addDecision(); the gate does not own it and no longer reaches
    // for it.
  })();
</script>
<!-- The connection to the Mercure hub is rendered here when a hub is wired; it feeds MilpaShell. -->
<!--LIVE-->
</body>
</html>
HTML;
    }
}
