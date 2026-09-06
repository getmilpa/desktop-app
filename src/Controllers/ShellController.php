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
use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\DesktopSettings;
use Milpa\DesktopApp\Http\RequestPrincipal;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\ActivityComponent;
use Milpa\DesktopApp\Live\AgentMessageComponent;
use Milpa\DesktopApp\Live\AuthOverlay;
use Milpa\DesktopApp\Live\AuthOverlayComponent;
use Milpa\DesktopApp\Live\CapabilitiesScreen;
use Milpa\DesktopApp\Live\CapabilitiesScreenComponent;
use Milpa\DesktopApp\Live\CommandListView;
use Milpa\DesktopApp\Live\ComposerBar;
use Milpa\DesktopApp\Live\ComposerBarComponent;
use Milpa\DesktopApp\Live\ComposerField;
use Milpa\DesktopApp\Live\ContextComponent;
use Milpa\DesktopApp\Live\ConversationComponent;
use Milpa\DesktopApp\Live\DecisionsInbox;
use Milpa\DesktopApp\Live\DecisionsInboxComponent;
use Milpa\DesktopApp\Live\DesktopAssets;
use Milpa\DesktopApp\Live\DesktopComponents;
use Milpa\DesktopApp\Live\GateComponent;
use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\DesktopApp\Live\ResultClaimComponent;
use Milpa\DesktopApp\Live\ScreenPreview;
use Milpa\DesktopApp\Live\ScreenPreviewComponent;
use Milpa\DesktopApp\Live\SessionStrip;
use Milpa\DesktopApp\Live\SessionStripComponent;
use Milpa\DesktopApp\Live\SettingsScreen;
use Milpa\DesktopApp\Live\SettingsScreenComponent;
use Milpa\DesktopApp\Live\ShellSignals;
use Milpa\DesktopApp\Live\SidebarComponent;
use Milpa\DesktopApp\Live\SkillsScreen;
use Milpa\DesktopApp\Live\SkillsScreenComponent;
use Milpa\DesktopApp\Live\StatusBar;
use Milpa\DesktopApp\Live\StatusBarComponent;
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
        private readonly ?ComposerBar $composerBar = null,
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
        $this->live->declare(new ComposerBarComponent(), fn (array $props): string => $this->composerBarOf()->render());
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
        // Phase D: the four screens whose markup the template still carried and whose behaviour and CSS
        // the page's own inline script and `<style>` still paid for. Each is a declared view now, and each
        // is built HERE from the registry's own codec — one signing key per page (greenhouse
        // decisions/0211), which is also why they need no constructor argument of their own.
        $this->live->declare(new CapabilitiesScreenComponent(), fn (array $props): string => (new CapabilitiesScreen($this->live->codec(), $this->data, $this->events, $this->catalog()))->render());
        $this->live->declare(new SkillsScreenComponent(), fn (array $props): string => (new SkillsScreen($this->live->codec(), $this->data, $this->events, $this->catalog()))->render());
        $this->live->declare(new ScreenPreviewComponent(), fn (array $props): string => (new ScreenPreview($this->live->codec(), $this->data, $this->events, $this->catalog()))->render());
        $this->live->declare(new DecisionsInboxComponent(), fn (array $props): string => (new DecisionsInbox($this->live->codec(), $this->data, $this->events, $this->catalog()))->render());
        $this->live->declare(new StatusBarComponent(), fn (array $props): string => (new StatusBar($this->live->codec(), $this->data, $this->events, $this->catalog()))->render());
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

    /**
     * The doors the client guard needs to know about (`#milpa-desktop-guard`), as JSON DATA.
     *
     * ONE key today: `signin`, the app's own sign-in path — and only when this Desktop actually stands
     * behind the passkey gate, so a Desktop on the loopback gate never sends anyone to a door it has not
     * got. It is the FALLBACK for a 401 whose body names no door: the Desktop's own routes answer
     * `{"signin":"…"}`, but app-runtime's operation doors (`/agent`, `/agent/goal`, `/skill/invoke`) answer
     * a bare `MILPA_UNAUTHENTICATED` — measured — and a session that expired mid-page left the human
     * reading a raw runtime error with no way back in.
     */
    private function guardJson(): string
    {
        $gate = ($this->settings ?? new DesktopSettings())->gateLabel();

        return (string) json_encode(
            ['signin' => $gate === DesktopSettings::GATE_PASSKEY ? DesktopAppPlugin::SIGNIN_PATH : ''],
            \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES,
        );
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
        // The page's own runtime modules lead (greenhouse decisions/0211): the shared guard every component
        // module reaches for, then the bus, the hub, the turn and the commands — five behaviours with no
        // surface to declare them ({@see DesktopAssets::runtimeModules()} is the one list).
        $assets = new ClientAssets(scripts: array_map(
            static fn (string $module): string => DesktopAssets::url($module, 'js'),
            DesktopAssets::runtimeModules(),
        ));
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
        $composer = $paint('desktop-composer');
        $thinking = $paint('desktop-thinking');
        $agentMessage = $paint('desktop-agent-message');
        $userMessage = $paint('desktop-user-message');
        $toolMessage = $paint('desktop-tool-call');
        $taskMessage = $paint('desktop-task');
        $systemMessage = $paint('desktop-system-notice');
        $resultMessage = $paint('desktop-result-claim');
        $settings = $paint('desktop-settings');
        $capabilities = $paint('desktop-capabilities');
        $skills = $paint('desktop-skills');
        $screens = $paint('desktop-screens');
        $decisions = $paint('desktop-decisions');
        $statusbar = $paint('desktop-statusbar');
        $auth = $paint('desktop-auth');

        return str_replace(
            [
                '<!--CONTEXT-->', '<!--CAPABILITIES-->', '<!--SKILLS-->', '<!--SCREENS-->', '<!--DECISIONS-->', '<!--SETTINGS-->',
                '<!--SIDEBAR-->', '<!--STATUSBAR-->', '<!--WORK-->', '<!--ACTIVITY-->', '<!--COMPOSER-->', '<!--AUTH-->', '<!--TOPBAR-->', '<!--TABS-->', '<!--GATE-->', '<!--CONVERSATION-->', '<!--THINKING-->', '<!--AGENTMSG-->', '<!--USERMSG-->', '<!--TOOLMSG-->', '<!--TASKMSG-->', '<!--SYSMSG-->', '<!--RESULTMSG-->', '<!--LIVERUNTIME-->', '<!--AGENTSID-->', '<!--HUB-->', '<!--COMMANDS-->',
                '<!--I18N-->', '<!--GUARD-->', '<!--EMBED-->', '<!--SESSIONSTRIP-->',
            ],
            [
                $context, $capabilities, $skills, $screens, $decisions, $settings,
                $sidebar, $statusbar, $work, $activity, $composer, $auth, $topbar, $tabs, $gate, $conversation, $thinking, $agentMessage, $userMessage, $toolMessage, $taskMessage, $systemMessage, $resultMessage, $this->liveRuntime($boot, $assets), $this->sessionJson($agentSid), $this->hubJson($agentSid), $this->commandsJson(),
                $this->i18nJson(), $this->guardJson(), $embed ? ' data-embed="1"' : '', $sessionStrip,
            ],
            $this->template(),
        );
    }

    /**
     * ONE runtime per page (greenhouse decisions/0211): the seeds the local runtime reads, then everything
     * `LiveBoot::html()` emits — the declared stylesheets, the boot payload, `milpa-live.js`,
     * `milpa-live-remote.js`, the five modules the page itself declares (the guard, the bus, the hub, the
     * turn and the commands — none of them a surface), every declared component module in the order its
     * surface was painted, and Alpine last, each `defer`, each URL once. The shell hand-writes no runtime
     * `<script>` tag any more.
     *
     * The three seed tags are emitted HERE and only here — `LiveBoot` carries the boot, not the signals — so
     * the page never has two places that could disagree about what the store starts with.
     */
    private function liveRuntime(LiveBoot $boot, ClientAssets $assets): string
    {
        $seeds = '<script id="milpa-live-signals" type="application/json">' . str_replace('</', '<\/', $this->liveSignals()) . '</script>' . "\n"
            // NO SIGNAL is persisted by the runtime: the mode is seeded from the SAVED setting on every load
            // (one truth, server-side — greenhouse decisions/0202) and the session summary is DERIVED, so a
            // reload reads the server, never the browser. The one thing the browser does remember is the
            // THEME, which `desktop-topbar.js` keeps in `localStorage` because it is the viewer's own
            // preference and no server owns it — the open tab and the composer's draft do NOT survive a
            // reload, and nothing in this package claims they do.
            . '<script id="milpa-live-persist" type="application/json">[]</script>' . "\n"
            . '<script id="milpa-live-computed" type="application/json">' . json_encode(ShellSignals::computed(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

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

    /**
     * The composer bar surface (greenhouse decisions/0211, C2) — the injected one, else a fallback.
     *
     * The last surface the shell stitched by hand: it is a declared view now ({@see ComposerBar}), so its
     * markup comes from a renderer, its behaviour from `desktop-composer.js` and its state travels in a
     * signed envelope like every other component's.
     */
    private function composerBarOf(): ComposerBar
    {
        return $this->composerBar ?? new ComposerBar(
            hash('sha256', __DIR__ . '|milpa-live|signing'),
            $this->data,
            $this->composerField,
            $this->events,
            $this->catalog(),
        );
    }

    /**
     * The agent session this Desktop drives, as JSON DATA (`#milpa-desktop-session`).
     *
     * The server minted it, set it in a cookie and scoped the hub's JWT to its exact stream topic
     * (greenhouse decisions/0190); the turn's module reads it from here. It is a `type="application/json"`
     * tag on purpose: the page carries no executable script of its own for the modules to read a value
     * out of (greenhouse decisions/0211, phase C).
     */
    private function sessionJson(string $agentSid): string
    {
        return (string) json_encode(['agent' => $agentSid], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES);
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

    /**
     * The initial shared signals, seeded into the page — one truth projected across the UI
     * (decisions/0189), and the SAME map the Agent region of a host panel seeds ({@see ShellSignals}):
     * two pages mount these components now, and a second copy of this map would be a second truth about
     * what a fresh page starts with.
     */
    private function liveSignals(): string
    {
        return (string) json_encode(ShellSignals::of($this->catalog(), $this->data), \JSON_UNESCAPED_SLASHES);
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
        return $this->conversation ?? new \Milpa\DesktopApp\Live\Conversation('desktop-conversation-fallback', $this->events, $this->data, $this->catalog());
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
     * The Mercure hub this page connects to, as JSON DATA (`#milpa-desktop-hub`).
     *
     * The connector used to be an inline `<script>` with the hub's URL baked into it; it is a declared
     * runtime module now (`desktop-hub.js`, greenhouse decisions/0211, phase D1), so the URL travels the
     * way every other server fact does — a `type="application/json"` tag the module READS and nobody
     * executes. An empty object is a Desktop with no hub wired: the module says «offline» once and opens
     * nothing.
     *
     * TWO exact topics on ONE connection: the shell topic (the Desktop's own {@see ShellEvent}s) and this
     * session's stream (a governed turn's `session.*` projection, greenhouse decisions/0190). Exact
     * topics, not a URI template, so the hub authorizes and delivers them without matching ambiguity.
     */
    private function hubJson(string $agentSid): string
    {
        if ($this->mercure === null) {
            return '{}';
        }

        $url = $this->mercure->publicUrl
            . '?topic=' . rawurlencode($this->mercure->topic)
            . '&topic=' . rawurlencode(MercureConfig::sessionTopic($agentSid));

        return (string) json_encode(['url' => $url], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES);
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
  /* WHAT IS STILL HERE, and why (greenhouse decisions/0211, phase D5). Only the SHELL's own frame:
     the document, the window strip, the grid the views sit in, and three document-wide rules. Every
     other rule this block used to carry belonged to a surface — the capability cards, the decision
     cards, the skill and role cards, the preview chips, the command popup, the composer box, the
     status bar, the work board, the panel grid, the interrupted notice — and now lives in the
     stylesheet of the component that owns it, declared by its renderer and emitted by LiveBoot. The
     test for that claim is not this comment: it is that each of those files exists and is served. */
  body { margin: 0; background: var(--bg); color: var(--text); font-family: var(--font-body); }
  .app { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
  .chrome { flex: none; display: flex; align-items: center; gap: var(--space-3); height: 46px; padding: 0 var(--space-4); border-bottom: 1px solid var(--border-subtle); background: var(--surface); }
  .chrome__search { max-width: 280px; margin-inline-start: var(--space-4); font-family: var(--font-mono); font-size: var(--text-xs); }
  .chrome__served { margin-inline: auto; font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); }
  .lights { display: flex; gap: 8px; }
  .lights span { width: 12px; height: 12px; border-radius: 99px; }
  .lights__danger { background: var(--danger); }
  .lights__warning { background: var(--warning); }
  .lights__success { background: var(--success); }
  /* The `hidden` attribute must win over an element's own display — every view, the auth overlay, the
     composer's floating panels and the tab panes carry both, so without !important they could never
     actually hide. This is what makes "Open workspace" reveal the dashboard and a nav item swap views. */
  [hidden] { display: none !important; }
  .tabpane[hidden] { display: none; }
  /* The shell's grid and the frame of the one view the shell itself lays out (the session): every OTHER
     view is a declared component and lays itself out in its own stylesheet. */
  .mui-shell { flex: 1; min-height: 0; --_sidebar-w: 17.5rem; overflow: hidden; display: grid; grid-template-columns: var(--_sidebar-w) minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr); }
  .mui-shell__main { grid-row: 2; grid-column: 2; min-height: 0; padding: 0; display: flex; flex-direction: column; overflow: hidden; }
  .view--session { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; }
  .view--session__scroll { flex: 1; min-height: 0; overflow: auto; padding: var(--space-6) var(--space-8); }
  .view--session__dock { flex: none; padding: var(--space-3) var(--space-8) var(--space-5); border-top: 1px solid var(--border-subtle); background: var(--bg); }
  .tabpane--activity { display: grid; grid-template-columns: 1fr 20rem; gap: var(--space-6); align-items: start; }
  .tabpane__intro { color: var(--text-secondary); font-size: var(--text-sm); margin: 0 0 var(--space-4); }
  /* Embed mode (greenhouse decisions/0210): the Desktop as the admin's guest. The chrome FOLDS — the window
     strip, the sidebar, the topbar and the status bar are not visible and the main takes the whole grid — but
     the DOM stays: every id the shell's modules look up at boot is still in the document. The session strip
     above the conversation is what keeps the sessions reachable while the sidebar is folded. */
  html[data-embed="1"] .chrome, html[data-embed="1"] .statusbar, html[data-embed="1"] .mui-sidebar, html[data-embed="1"] .mui-topbar { display: none !important; }
  html[data-embed="1"] .mui-shell { grid-template-columns: minmax(0, 1fr) !important; grid-template-rows: minmax(0, 1fr) !important; }
  html[data-embed="1"] .mui-shell__main { grid-column: 1 !important; grid-row: 1 !important; }
  /* One tune of a design-system class, used by seven different surfaces (the sidebar, the context panels,
     the work board, the capabilities, the skills, the roles and the preview all print a `.mui-empty`): no
     single component owns it, so the shell does — the way it owns `[hidden]` and the scrollbars. */
  .mui-empty { color: var(--text-muted); font-size: var(--text-sm); }
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
<div class="app">

  <div class="chrome">
    <span class="lights"><span class="lights__danger"></span><span class="lights__warning"></span><span class="lights__success"></span></span>
    <input id="milpa-search" type="search" placeholder="Search sessions…" aria-label="Search sessions" autocomplete="off" class="mui-input mui-input--sm chrome__search">
    <span class="chrome__served">served by Milpa · one origin</span>
    <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm" id="milpa-auth-open">Open workspace</button>
    <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm" id="milpa-theme" aria-label="toggle theme">◐ Theme</button>
  </div>

  <div class="mui-shell">
    <!--SIDEBAR-->

    <!--TOPBAR-->

    <main class="mui-shell__main mui-shell__main--wide">
      <div class="view view--session" data-view="session" x-data>
      <!-- Embed mode only (greenhouse decisions/0210): the session strip — the sidebar's reach in one row. -->
      <!--SESSIONSTRIP-->
      <!--TABS-->

      <div class="view--session__scroll">

        <!-- The conversation is a Milpa Component that COMPOSES the message components (greenhouse
             decisions/0191): user/agent/thinking/tool/task/system messages are cloned in from their own
             components' prototypes, and the consent gate lives here too. Its empty state hides once a
             message lands. -->
        <section class="tabpane milpa-chat" data-pane="chat" id="milpa-chat" data-milpa-component="desktop-conversation" data-milpa-component-id="conversation" x-data="desktopConversation()" @click="onClick($event)" :hidden="$store.milpa['desktop.tab'] !== 'chat'">
          <!--CONVERSATION-->

          <!-- The consent gate is the `desktop-gate` component (greenhouse decisions/0189): hidden until an
               agent parks a question; its visibility is the shared `desktop.gate.open` signal. -->
          <!--GATE-->
        </section>

        <section class="tabpane" data-pane="work" hidden :hidden="$store.milpa['desktop.tab'] !== 'work'">
          <p class="tabpane__intro">The session's work board — todo items by status.</p>
          <!--WORK-->
        </section>

        <section class="tabpane tabpane--activity" data-pane="activity" hidden :hidden="$store.milpa['desktop.tab'] !== 'activity'">
          <!--ACTIVITY-->
        </section>

        <section class="tabpane" data-pane="context" hidden :hidden="$store.milpa['desktop.tab'] !== 'context'">
          <!-- The panel grid is the `desktop-context` component; plugins contribute panels via addPanel. -->
          <!--CONTEXT-->
        </section>

      </div>
      <!-- The composer is docked below the scroll, sticky at the bottom: messages flow above it and it
           stays put. Shown only on the Conversation tab. -->
      <div id="milpa-composer-dock" class="view--session__dock" :hidden="$store.milpa['desktop.tab'] !== 'chat'"><!--COMPOSER--></div>
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

      <!-- Every OTHER view of the main is a declared component now (greenhouse decisions/0211, phases B and
           D): each renders its own `.view` root with its own `data-view` key, so the sidebar swaps between
           them exactly as before, and each brings its own stylesheet and its own module. The shell no longer
           writes a line of their markup, their look or their behaviour. -->
      <!--SETTINGS-->
      <!--CAPABILITIES-->
      <!--SKILLS-->
      <!--SCREENS-->
      <!--DECISIONS-->

    </main>
  </div>

  <!-- The status bar is a component too (greenhouse decisions/0211, D4): the connection, the model and the
       counters, each BOUND to a signal — nothing reaches into it by id any more. -->
  <!--STATUSBAR-->
</div>

<!-- The entry overlay — «Open workspace» — is the `desktop-auth` component now (greenhouse
     decisions/0211): its markup comes from a renderer, its look from desktop-auth.css and its ceremony
     from desktop-auth.js. Its visibility is the shared `desktop.auth.open` signal. -->
<!--AUTH-->

<!-- THE PAGE'S OWN SCRIPTS, in full (greenhouse decisions/0211, phase D): five `application/json` tags and
     nothing else. Not one line of behaviour is written into this document — every verb the Desktop has
     lives in a file a renderer declared and LiveBoot emitted, and these tags are the DATA those files read.
     An executable tag here would be a regression, and DeclaredViewsTest fails on it. -->
<!-- The composer's commands (greenhouse decisions/0202): the house's own plus every user-invocable skill. -->
<script id="milpa-commands" type="application/json"><!--COMMANDS--></script>
<!-- The Desktop's copy in the declared locale (greenhouse decisions/0209): what every module says. -->
<script id="milpa-desktop-i18n" type="application/json"><!--I18N--></script>
<!-- The doors the client guard needs: the app's sign-in path, for a 401 whose body names none. -->
<script id="milpa-desktop-guard" type="application/json"><!--GUARD--></script>
<!-- The agent session this Desktop drives (greenhouse decisions/0190): the turn's module reads it. -->
<script id="milpa-desktop-session" type="application/json"><!--AGENTSID--></script>
<!-- The Mercure hub, with its two exact topics — `{}` when this Desktop has none (decisions/0211, D1). -->
<script id="milpa-desktop-hub" type="application/json"><!--HUB--></script>
</body>
</html>
HTML;
    }
}
