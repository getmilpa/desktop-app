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
        // The shared guard leads the Desktop's own modules — every one of them reaches for it — and the
        // rest follow in the order the surfaces were painted (greenhouse decisions/0211, phase B).
        '<script src="/desktop/assets/c/desktop-guard.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-sidebar.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-topbar.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-tabs.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-gate.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-activity.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-settings.js" defer></script>',
        '<script src="/desktop/assets/c/desktop-auth.js" defer></script>',
        '<script src="/desktop/assets/alpine.min.js" defer></script>',
    ];

    /** Every stylesheet the plain page's surfaces declare, in the order they are painted. */
    private const STYLE_ORDER = [
        'desktop-sidebar', 'desktop-topbar', 'desktop-tabs', 'desktop-conversation', 'desktop-gate',
        'desktop-activity', 'desktop-thinking', 'desktop-agent-message', 'desktop-user-message',
        'desktop-tool-call', 'desktop-task', 'desktop-system-notice', 'desktop-result-claim',
        'desktop-settings', 'desktop-auth',
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
        self::assertSame(18, $painted, 'every shell surface is a declared view');
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
                    '/desktop/assets/c/desktop-sidebar.js',
                    '/desktop/assets/c/desktop-topbar.js',
                    '/desktop/assets/c/desktop-tabs.js',
                    '/desktop/assets/c/desktop-gate.js',
                    '/desktop/assets/c/desktop-activity.js',
                    '/desktop/assets/c/desktop-settings.js',
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

    public function testTheShellsInlineScriptKeepsOnlyTheGroupsALaterSliceMoves(): void
    {
        // The residue, named: the conversation and the turn (with the composer's commands and mode chip),
        // the capabilities two-step, the work board's drag-drop, the screen preview and the connection
        // status are still the page's. Naming them is what makes the next slice's «gone from the page»
        // assertion meaningful rather than a guess.
        $page = $this->page();

        foreach ([
            'function appendMessage(', 'function renderMarkdown(', 'function appendReasoning(',
            'function runTurn(', 'function send(', 'function runCommand(', 'function applyMode(',
            'function capEnable(', "querySelector('.work-board')", "getElementById('milpa-preview-frame')",
            "getElementById('milpa-conn')",
        ] as $stillHere) {
            self::assertStringContainsString($stillHere, $page, $stillHere . ' is a later slice');
        }
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
        foreach (['desktop-thinking.js', 'desktop-conversation.js', 'unknown.css', '../../composer.json', 'desktop-guard.css', 'desktop-guard'] as $refused) {
            self::assertSame(404, $serve($refused)->getStatusCode(), $refused . ' is refused');
        }
        // And a request that carries no route result at all.
        self::assertSame(404, $controller->component(new ServerRequest('GET', DesktopAssets::BASE))->getStatusCode());
    }
}
