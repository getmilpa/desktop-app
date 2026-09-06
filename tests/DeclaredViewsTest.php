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

use Milpa\DesktopApp\Controllers\AssetsController;
use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\Live\DesktopAssets;
use Milpa\DesktopApp\Live\DesktopComponentRenderer;
use Milpa\DesktopApp\Live\DesktopComponents;
use Milpa\DesktopApp\Live\SidebarComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\ComponentContext;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Declared views, ONE runtime per page (greenhouse decisions/0211).
 *
 * The old contract this replaces was a count of 35 element ids and a set of literal JS fragments in the
 * page: it pinned the shell's hand-stitching, so it could only ever say the stitching had not changed.
 * The new contract is what the doctrine actually claims:
 *
 *   (a) every `desktop-*` renderer DECLARES its own client files — and the declaration is true, the file
 *       is in the package (a `<link>` at a missing file 404s in silence, so the falsifier is the disk);
 *   (b) the page emits its runtime ONLY through `LiveBoot::html()`, in the documented order, each URL once
 *       — no hand-written runtime `<script>` tag survives anywhere in the document;
 *   (c) the page is COMPOSED through the compiler over the ONE registry, and `POST /desktop/live` is built
 *       over that same registry;
 *   (d) the per-component route family actually serves what the renderers declared, and refuses anything
 *       else.
 */
final class DeclaredViewsTest extends TestCase
{
    /** The order `LiveBoot::html()` documents: styles → boot → local → remote → plugin modules → Alpine. */
    private const RUNTIME_ORDER = [
        '<script id="milpa-live-boot" type="application/json">',
        '<script src="/desktop/assets/milpa-live.js" defer></script>',
        '<script src="/desktop/assets/milpa-live-remote.js" defer></script>',
        // The five modules the PAGE declares lead — the guard creates `MilpaLive.desktop`, the bus creates
        // `window.MilpaShell`, the hub subscribes the transport to it, and the turn and the commands hang
        // off the guard — and the component modules follow in the order their surfaces were painted
        // (greenhouse decisions/0211, phases B, C and D).
        '<script src="/desktop/assets/c/desktop-guard.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-shell-bus.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-hub.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-turn.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-commands.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-sidebar.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-topbar.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-tabs.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-conversation.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-gate.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-work-board.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-activity.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-composer.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-thinking.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-agent-message.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-tool-call.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-result-claim.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-settings.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-capabilities.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-screens.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-decisions.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-auth.js" defer></script>',
        '<script src="/desktop/assets/alpine.min.js" defer></script>',
    ];

    /** Every stylesheet the plain page's surfaces declare, in the order they are painted. */
    private const STYLE_ORDER = [
        'desktop-sidebar', 'desktop-topbar', 'desktop-tabs', 'desktop-conversation', 'desktop-gate',
        'desktop-work-board', 'desktop-activity', 'desktop-context', 'desktop-composer', 'desktop-thinking',
        'desktop-agent-message', 'desktop-user-message', 'desktop-tool-call', 'desktop-task',
        'desktop-system-notice', 'desktop-result-claim', 'desktop-settings', 'desktop-capabilities',
        'desktop-skills', 'desktop-screens', 'desktop-decisions', 'desktop-statusbar', 'desktop-auth',
    ];

    private function shell(): ShellController
    {
        return new ShellController(new EventDispatcher(new NullLogger()));
    }

    private function page(bool $embed = false): string
    {
        $request = new ServerRequest('GET', '/desktop' . ($embed ? '?embed=1' : ''));

        return (string) $this->shell()->shell($request)->getBody();
    }

    public function testEveryDeclaredFileIsAFileThePackageShips(): void
    {
        // The falsifier for a lying declaration: a renderer may only name files that exist. Mutating the map
        // (an extension a component does not ship) makes this red — which is the point: a missing stylesheet
        // is invisible in a browser.
        self::assertNotSame([], DesktopAssets::declared());
        foreach (DesktopAssets::declared() as $component) {
            $assets = DesktopAssets::of($component);
            self::assertFalse($assets->isEmpty(), $component . ' declares at least one file');
            foreach ([...$assets->styles, ...$assets->scripts] as $url) {
                self::assertStringStartsWith(DesktopAssets::BASE, $url);
                $path = DesktopAssets::path(substr($url, \strlen(DesktopAssets::BASE)));
                self::assertNotNull($path, $url . ' resolves to a package path');
                self::assertFileExists($path, $url . ' is declared and must exist');
            }
        }
    }

    public function testEachRendererDeclaresExactlyItsOwnFiles(): void
    {
        // A renderer's assets are the RENDERER's, not the instance's: one renderer per component, each
        // naming its own css (and its own js when it has behaviour) and never another's.
        $components = $this->shell()->components();
        $painted = 0;
        foreach ($components->names() as $name) {
            $renderer = $components->renderers()->resolveFor($name, RenderTarget::HTML);
            self::assertNotNull($renderer, $name . ' has a renderer');
            if (!str_starts_with($name, 'desktop-')) {
                continue; // the shipped form primitives are milpa/live's own, not declared views
            }
            ++$painted;
            self::assertInstanceOf(DeclaresClientAssets::class, $renderer, $name . ' declares its client assets');
            self::assertEquals(DesktopAssets::of($name), $renderer->clientAssets(), $name . ' declares exactly its own files');
        }
        self::assertSame(24, $painted, 'every shell surface is a declared view');
    }

    public function testThePageEmitsOneRuntimeThroughLiveBootInTheDocumentedOrder(): void
    {
        $page = $this->page();

        // (1) Every declared stylesheet, before any script, each once.
        $expectedStyles = [];
        foreach (self::STYLE_ORDER as $component) {
            $link = '<link rel="stylesheet" href="' . DesktopAssets::url($component, 'css') . '">';
            self::assertSame(1, substr_count($page, $link), $component . ' stylesheet emitted once');
            $expectedStyles[] = strpos($page, $link);
        }
        $sorted = $expectedStyles;
        sort($sorted);
        self::assertSame($sorted, $expectedStyles, 'the stylesheets are emitted in the order the surfaces were painted');
        self::assertLessThan(strpos($page, self::RUNTIME_ORDER[0]), max($expectedStyles), 'every stylesheet precedes the boot');

        // (2..6) The boot, the two runtimes, the declared modules, Alpine — in that order, each once.
        $at = -1;
        foreach (self::RUNTIME_ORDER as $tag) {
            self::assertSame(1, substr_count($page, $tag), $tag . ' is emitted exactly once');
            $position = strpos($page, $tag);
            self::assertGreaterThan($at, $position, $tag . ' is out of order');
            $at = $position;
        }

        // The session strip's stylesheet rides ONLY the page that renders the strip.
        self::assertStringNotContainsString(DesktopAssets::url('desktop-session-strip', 'css'), $page);
        self::assertStringContainsString(DesktopAssets::url('desktop-session-strip', 'css'), $this->page(embed: true));
    }

    public function testTheShellHandWritesNoRuntimeScriptTag(): void
    {
        foreach ([$this->page(), $this->page(embed: true)] as $page) {
            preg_match_all('/<script[^>]*\bsrc="([^"]+)"[^>]*>/', $page, $m);
            // Every external script is one LiveBoot emitted: the three runtime files and the declared
            // modules, in declaration order — nothing the shell hand-wrote, and no URL twice.
            self::assertSame(
                [
                    '/desktop/assets/milpa-live.js',
                    '/desktop/assets/milpa-live-remote.js',
                    '/desktop/assets/c/desktop-guard.js',
                    '/desktop/assets/c/desktop-shell-bus.js',
                    '/desktop/assets/c/desktop-hub.js',
                    '/desktop/assets/c/desktop-turn.js',
                    '/desktop/assets/c/desktop-commands.js',
                    '/desktop/assets/c/desktop-sidebar.js',
                    '/desktop/assets/c/desktop-topbar.js',
                    '/desktop/assets/c/desktop-tabs.js',
                    '/desktop/assets/c/desktop-conversation.js',
                    '/desktop/assets/c/desktop-gate.js',
                    '/desktop/assets/c/desktop-work-board.js',
                    '/desktop/assets/c/desktop-activity.js',
                    '/desktop/assets/c/desktop-composer.js',
                    '/desktop/assets/c/desktop-thinking.js',
                    '/desktop/assets/c/desktop-agent-message.js',
                    '/desktop/assets/c/desktop-tool-call.js',
                    '/desktop/assets/c/desktop-result-claim.js',
                    '/desktop/assets/c/desktop-settings.js',
                    '/desktop/assets/c/desktop-capabilities.js',
                    '/desktop/assets/c/desktop-screens.js',
                    '/desktop/assets/c/desktop-decisions.js',
                    '/desktop/assets/c/desktop-auth.js',
                    '/desktop/assets/alpine.min.js',
                ],
                $m[1],
            );
            self::assertSame(\count($m[1]), \count(array_unique($m[1])), 'no URL is emitted twice');
            foreach ($m[0] as $tag) {
                self::assertStringContainsString(' defer>', $tag, 'LiveBoot defers every script it emits');
            }
            // The seeds the local runtime reads sit next to the boot, and each exists exactly once.
            foreach (['milpa-live-signals', 'milpa-live-persist', 'milpa-live-computed', 'milpa-live-boot'] as $id) {
                self::assertSame(1, substr_count($page, '<script id="' . $id . '" type="application/json">'), $id);
            }
            // The page's own data tags, read by the modules and executed by nobody (greenhouse
            // decisions/0211, phase C): the command list, the copy, the door the guard falls back to, the
            // agent session the turn carries, and the hub the transport opens.
            foreach (['milpa-commands', 'milpa-desktop-i18n', 'milpa-desktop-guard', 'milpa-desktop-session', 'milpa-desktop-hub'] as $id) {
                self::assertSame(1, substr_count($page, '<script id="' . $id . '" type="application/json">'), $id);
            }
        }
    }

    /**
     * Phase B's whole claim, as one falsifiable pair (greenhouse decisions/0211).
     *
     * A behaviour that MOVED is a behaviour that is gone from the page AND present in exactly one module.
     * Asserting only the first half would pass if the behaviour had simply been deleted; asserting only the
     * second would pass if the page still carried its own copy. The map below is the contract, read both
     * ways — and the shell's own script keeps only the groups a later slice moves.
     *
     * @return iterable<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function movedBehaviours(): iterable
    {
        yield 'the tab switch' => ['desktop-tabs', ['select: function (key)', 'isActive: function (key)'], ['function showTab(']];
        yield 'the theme' => ['desktop-topbar', ['function apply(', 'function remember('], ['function applyTheme(', 'function persistTheme(', "getElementById('milpa-theme')"]];
        yield 'the gate fill' => ['desktop-gate', ['function intentHref(', "MilpaShell.on('gate.opened'"], ["MilpaShell.on('gate.opened'", 'function setGateOpen(']];
        yield 'the activity stream' => ['desktop-activity', ['record: function (type, data)'], ['MilpaShell.onAny(']];
        yield 'the navigation and the search' => ['desktop-sidebar', ['function showView(', 'function filterSessions(', 'function newSession('], ['function showView(', 'var navToView =', "getElementById('milpa-search')"]];
        yield 'the settings save' => ['desktop-settings', ['save: function ()', 'discard: function ()'], ['function showSaved(', "getElementById('milpa-save-settings')"]];
        yield 'the session ceremony' => ['desktop-auth', ['enter: function ()'], ['function openNewSession(', "getElementById('milpa-auth-enter')"]];
        // Phase C: the conversation and its message kinds, the composer, the turn and the commands.
        yield 'the thread' => ['desktop-conversation', ['function append(kind, opts)', 'd.onNotice(function (notice)'], ['function appendMessage(', 'var MSG_PROTOS =', 'function region(root, selector)']];
        yield 'the thinking block' => ['desktop-thinking', ['delta: function (thread, text)', 'end: function ()'], ['function appendReasoning(', 'function endReasoning(']];
        yield "the answer's markdown and tools" => ['desktop-agent-message', ['function renderMarkdown(', 'verdict: function (thread, ok, reasons)'], ['function renderMarkdown(', 'function markAgentVerdict(', '[data-agent-copy]']];
        yield "a tool result's reading" => ['desktop-tool-call', ['function summary(raw)', 'function pretty(raw)'], ['function toolSummary(', 'function prettyMaybe(']];
        yield 'the closure claim' => ['desktop-result-claim', ['fill: function (root, opts, at)'], ['var ok = o.verified !== false;']];
        yield 'the composer' => ['desktop-composer', ['send: function ()', 'applyMode: function (key)'], ['function send(', 'function applyMode(', 'function setComposerText(', "getElementById('milpa-charcount')"]];
        yield 'the governed turn' => ['desktop-turn', ['function run(text)', 'function counters(result)'], ['function runTurn(', 'function updateCounters(', "fetch('/agent'"]];
        yield 'the slash commands' => ['desktop-commands', ['function parse(text)', 'function handlesKey(event)'], ['function parseCommand(', 'function runCommand(', 'function callOp(', 'function opFailure(', 'function refreshCommandList(']];
        // Phase D: the bus, the transport, and the four screens whose behaviour the page still carried.
        yield 'the shell bus' => ['desktop-shell-bus', ['window.MilpaShell = { on: on', 'function status(state)'], ['window.MilpaShell = (function ()', 'statusHandlers.forEach']];
        yield 'the hub connector' => ['desktop-hub', ['function translate(env)', 'new EventSource(URL'], ['new EventSource(', "window.MilpaShell.status('offline')", 'MilpaShell.session(env)']];
        yield 'the capabilities two-step' => ['desktop-capabilities', ['function enable(pkg)', "'Confirm-Token': answer.confirm_token"], ['function capEnable(', 'box.innerHTML', "capHost.addEventListener('click'"]];
        yield "the work board's drag" => ['desktop-work-board', ['onDragStart: function (event)', 'onDrop: function (event)'], ["querySelector('.work-board')", "fetch('/desktop/work'", 'col.style.background']];
        yield 'the screen preview' => ['desktop-screens', ['function preview()', 'function chip(button)'], ["getElementById('milpa-preview-frame')", "getElementById('milpa-preview-name')", 'frame.src = src']];
        yield 'the live inbox' => ['desktop-decisions', ['function parked(question)', "bus.on('decision.parked'"], ['addDecision: function (question)', "getElementById('milpa-decisions-list')", "createElement('ol')"]];
        yield 'the decisions badge' => ['desktop-sidebar', ['function parked()'], ['querySelector(\'[data-nav="decisions"] .mui-sidebar__item-badge\')', 'badge.style.marginInlineStart']];
    }

    /**
     * @param list<string> $inTheModule     what the component's module must now carry
     * @param list<string> $goneFromThePage what the shell's inline script must no longer carry
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('movedBehaviours')]
    public function testAMovedBehaviourIsGoneFromThePageAndPresentInItsModule(string $component, array $inTheModule, array $goneFromThePage): void
    {
        $page = $this->page();
        $module = (string) file_get_contents(\dirname(__DIR__) . '/resources/components/' . $component . '/' . $component . '.js');

        foreach ($inTheModule as $needle) {
            self::assertStringContainsString($needle, $module, $component . ' owns «' . $needle . '»');
        }
        foreach ($goneFromThePage as $needle) {
            self::assertStringNotContainsString($needle, $page, '«' . $needle . '» left the page for ' . $component);
        }
        // And the page LOADS it: a behaviour that moved to a file nobody fetches has not moved, it is lost.
        self::assertStringContainsString('<script src="' . DesktopAssets::url($component, 'js') . '" defer></script>', $page);
    }

    /**
     * The contract of phase D, which is the contract of the whole slice (greenhouse decisions/0211).
     *
     * Phase C's pin here NAMED what the page still carried, so that this phase's «gone» would be a claim
     * and not a guess. There is nothing left to name: the page carries no behaviour at all. What this
     * test asserts is therefore ONE list — every verb of every group A, B, C and D moved — read against
     * the document, with the pair above proving each of them landed in exactly one module.
     *
     * The list is long on purpose. A short list would pass on a page that had lost half of them for the
     * wrong reason.
     */
    public function testThePageCarriesNoBehaviourAtAll(): void
    {
        foreach ([$this->page(), $this->page(embed: true)] as $page) {
            // Phases A–C: the conversation, its message kinds, the composer, the turn and the commands.
            foreach ([
                'function appendMessage(', 'function renderMarkdown(', 'function appendReasoning(',
                'function endReasoning(', 'function markAgentVerdict(', 'var MSG_PROTOS =',
                'function toolSummary(', 'function prettyMaybe(',
                'function runTurn(', 'function updateCounters(', "fetch('/agent'",
                'function send(', 'function setComposerText(', 'function applyMode(', 'function refreshSend(',
                'function parseCommand(', 'function runCommand(', 'function callOp(', 'function opFailure(',
                'function refreshCommandList(', 'function commandListHandlesKey(', 'function cmdHide(',
                'function showTab(', 'function applyTheme(', 'function showView(', 'function showSaved(',
                'function openNewSession(', 'function setGateOpen(',
                // …and the shims those groups needed, which had no other caller.
                'function notice(text)', 'function whenGuard(', 'var agentSession =', 'function currentMode(',
            ] as $moved) {
                self::assertStringNotContainsString($moved, $page, '«' . $moved . '» is a declared view now');
            }

            // Phase D: the bus, the transport, and the four screens the page still drove.
            foreach ([
                'window.MilpaShell = (function ()', 'addDecision: function (question)', 'panel: function (id)',
                'new EventSource(', "window.MilpaShell.status('offline')", 'MilpaShell.session(env)',
                'function capEnable(', 'box.innerHTML',
                "querySelector('.work-board')", "fetch('/desktop/work'", 'col.style.background',
                "getElementById('milpa-preview-frame')", "getElementById('milpa-conn')",
                "getElementById('milpa-decisions-list')", 'badge.style.marginInlineStart',
                // The guard shims the residue script kept for itself went with it.
                'function desk() { return', 'function guardFlow(', 'function failed(err, unreachable)',
            ] as $moved) {
                self::assertStringNotContainsString($moved, $page, '«' . $moved . '» left the page in phase D');
            }

            // The positive control for the whole list: the page is not empty, and the surfaces those verbs
            // used to drive are all still IN it — what left is the behaviour, not the UI.
            foreach (['id="milpa-chat"', 'data-view="capabilities"', 'data-milpa-component="desktop-work-board"', 'id="milpa-preview-frame"', 'class="statusbar"', 'id="milpa-decisions-list"'] as $surface) {
                self::assertStringContainsString($surface, $page, $surface . ' is still painted');
            }
        }
    }

    /**
     * Every inline `<script>` the page carries is DATA. There is no other kind left.
     *
     * This is the slice's claim in one assertion: a `type` the browser does not execute — the JSON the
     * modules read and the signed state envelopes the runtime decodes — or nothing. An executable tag
     * (a plugin's, a regression, a debugging line someone left behind) fails it.
     */
    public function testTheOnlyInlineScriptsAreDataTags(): void
    {
        foreach ([$this->page(), $this->page(embed: true)] as $page) {
            preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/', $page, $m);
            self::assertGreaterThan(20, \count($m[1]), 'the instrument read no inline scripts — it would pass on an empty page');

            $executable = [];
            $json = 0;
            $envelopes = 0;
            foreach ($m[1] as $attributes) {
                if (preg_match('/type="application\/json"/', $attributes) === 1) {
                    ++$json;
                    continue;
                }
                if (preg_match('/type="application\/milpa\+xhtml"/', $attributes) === 1) {
                    ++$envelopes;
                    continue;
                }
                $executable[] = trim($attributes);
            }
            self::assertSame([], $executable, 'the page executes no script of its own');
            // And the data it does carry is exactly the five tags the modules read, plus one signed
            // envelope per painted surface and the three seeds the local runtime reads with the boot.
            self::assertSame(9, $json, 'three runtime seeds, the boot, and the five data tags');
            self::assertGreaterThanOrEqual(20, $envelopes, 'one signed envelope per painted surface');
        }

        // The control for the INSTRUMENT: it must actually see an executable tag when there is one —
        // otherwise the assertion above would hold on any page at all.
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/', '<script>alert(1)</script><script type="application/json">{}</script>', $m);
        self::assertCount(2, $m[1]);
        self::assertSame('', trim($m[1][0]), 'the instrument reads a bare executable tag');
    }

    public function testThePageAndTheLiveEndpointShareOneRegistry(): void
    {
        $shell = $this->shell();
        $registry = $shell->components();

        // The composer's primitives plus every shell surface, in one registry.
        self::assertTrue($registry->has('textarea'));
        self::assertTrue($registry->has('input'));
        foreach (['desktop-sidebar', 'desktop-topbar', 'desktop-tabs', 'desktop-gate', 'desktop-conversation', 'desktop-session-strip'] as $name) {
            self::assertTrue($registry->has($name), $name . ' is declared');
        }
        // The page is composed through the compiler over it — not stitched from strings.
        $compiled = $registry->compiler()->compile('<milpa-desktop-tabs/>', new ComponentContext(componentId: 'shell'));
        self::assertStringContainsString('data-milpa-component="desktop-tabs"', $compiled->output);
        // The markup the compiler produces IS the markup the page carries (the signed envelope that follows
        // it is freshly nonced per render, so the comparison is of the surface, not of the signature).
        $markup = substr($compiled->output, 0, (int) strpos($compiled->output, '<script type="application/milpa+xhtml"'));
        self::assertNotSame('', $markup);
        self::assertStringContainsString($markup, (string) $shell->shell(new ServerRequest('GET', '/desktop'))->getBody());
    }

    public function testARendererRefusesAComponentItDoesNotAnswerFor(): void
    {
        $renderer = new DesktopComponentRenderer('desktop-sidebar', static fn (array $props): string => 'painted');

        self::assertSame('desktop-sidebar', $renderer->component());
        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));

        $request = new RenderRequest(context: new ComponentContext(componentId: 'x'));
        self::assertSame('painted', $renderer->render(new SidebarComponent(), $request)->output);

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render(new \Milpa\DesktopApp\Live\TabsComponent(), $request);
    }

    public function testARendererRefusesATargetItDoesNotPaint(): void
    {
        $renderer = new DesktopComponentRenderer('desktop-sidebar', static fn (array $props): string => 'painted', new ClientAssets());

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render(new SidebarComponent(), new RenderRequest(context: new ComponentContext(componentId: 'x'), target: RenderTarget::TUI));
    }

    public function testTheRegistryAnswersForAComponentDeclaredAfterTheEndpointWasBuilt(): void
    {
        // The registry is shared by reference: the endpoint holds it, so a surface declared later is still
        // resolvable over the wire — that is what makes "one registry, page and endpoint" true.
        $registry = new DesktopComponents('sign', 'csrf');
        $endpoint = $registry->endpoint();
        self::assertFalse($registry->has('desktop-sidebar'));

        $registry->declare(new SidebarComponent(), static fn (array $props): string => 'late');

        self::assertTrue($registry->has('desktop-sidebar'));
        self::assertNotNull($registry->renderers()->resolveFor('desktop-sidebar', RenderTarget::HTML));
        self::assertNotSame('', $registry->csrfToken('sess-1'));
        self::assertSame(405, $endpoint->handle(new \Milpa\Live\Http\LiveHttpRequest('GET', 'change', '', [], 'sess', ''))->status, 'the same endpoint object still answers');
    }

    public function testTheComponentAssetRouteServesWhatTheRenderersDeclaredAndNothingElse(): void
    {
        $controller = new AssetsController();
        $route = new Route(path: DesktopAssets::BASE . '{file}', methods: HttpMethod::GET, handler: new HandlerReference(AssetsController::class, 'component'));
        $serve = static function (string $file) use ($controller, $route) {
            $request = (new ServerRequest('GET', DesktopAssets::BASE . $file))
                ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['file' => $file]));

            return $controller->component($request);
        };

        $css = $serve('desktop-thinking.css');
        self::assertSame(200, $css->getStatusCode());
        self::assertStringContainsString('text/css', $css->getHeaderLine('Content-Type'));
        self::assertStringContainsString('.milpa-think', (string) $css->getBody());

        $js = $serve('desktop-guard.js');
        self::assertSame(200, $js->getStatusCode());
        self::assertStringContainsString('javascript', $js->getHeaderLine('Content-Type'));
        self::assertStringContainsString('MilpaLive', (string) $js->getBody());

        // The URL carries NO version, and the file behind it changes with every release — so it may not be
        // immutable for a year: a browser holding last release's module against this release's markup is a
        // dead surface. One hour, the same policy the runtime files get (LiveController::serveAsset()).
        foreach ([$css, $js] as $response) {
            self::assertSame(AssetsController::BEHAVIOUR_CACHE, $response->getHeaderLine('Cache-Control'));
            self::assertStringNotContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        }

        // Anything the map does not name is a 404 — never a guess at a path, never a traversal.
        foreach (['desktop-user-message.js', 'desktop-task.js', 'unknown.css', '../../composer.json', 'desktop-guard.css', 'desktop-turn.css', 'desktop-guard'] as $refused) {
            self::assertSame(404, $serve($refused)->getStatusCode(), $refused . ' is refused');
        }
        // And a request that carries no route result at all.
        self::assertSame(404, $controller->component(new ServerRequest('GET', DesktopAssets::BASE))->getStatusCode());
    }
}
