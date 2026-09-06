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
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\Data\DesktopStore;
use Milpa\DesktopApp\DesktopSettings;
use Milpa\DesktopApp\Http\RequestPrincipal;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Tests\Fixtures\PasskeyGateStub;
use Milpa\DesktopApp\Live\CapabilityCatalogueView;
use Milpa\DesktopApp\Live\DecisionsInboxView;
use Milpa\DesktopApp\Live\RolesView;
use Milpa\DesktopApp\Live\ScreenPreviewView;
use Milpa\DesktopApp\Live\SkillsView;
use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\DesktopApp\Live\ShellEvent;
use Milpa\DesktopApp\Live\ShellEventLog;
use Milpa\Eventing\EventDispatcher;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The shell is served over HTTP at a real origin (greenhouse decisions/0188): that is the move that
 * dissolves the file:// constraint, so the shell must reach the passkey doors it shares an origin with.
 * With a Mercure hub wired (0475) it also carries the live client.
 */
final class ShellControllerTest extends TestCase
{
    private function controller(?MercureConfig $mercure = null): ShellController
    {
        // A real dispatcher with no subscribers: dispatch is a no-op, so the shell renders its base.
        return new ShellController(new EventDispatcher(new NullLogger()), $mercure);
    }

    /**
     * One declared component's client module, as the package ships it (greenhouse decisions/0211).
     *
     * Since the declared views a behaviour is EITHER in the page or in a module, never in both — so the
     * assertions that used to read one string now read two files, and the pair is the contract: present
     * where it belongs, absent where it was.
     */
    private static function module(string $component): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/resources/components/' . $component . '/' . $component . '.js');
    }

    /** One declared component's stylesheet, as the package ships it. */
    private static function style(string $component): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/resources/components/' . $component . '/' . $component . '.css');
    }

    public function testItServesTheShellAsHtml(): void
    {
        $res = $this->controller()->shell(new ServerRequest('GET', '/desktop'));

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Milpa Desktop', (string) $res->getBody());
    }

    public function testTheShellReachesThePasskeyDoorsInThisOrigin(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        // Same-origin links: the whole point of serving the shell over HTTP. Since the declared views
        // (greenhouse decisions/0211) the gate's ceremony link is BUILT by the gate's own module, so the
        // page carries the enrolment door and the module carries the intent door — both same-origin.
        self::assertStringContainsString('/webauthn/enroll', $body);
        self::assertStringContainsString('/webauthn/intent', self::module('desktop-gate'));
    }

    public function testTheClientRuntimeAndActivityComponentAreAlwaysServed(): void
    {
        // The component runtime is the reactive-renderer contract (0476): present with or without a hub, so a
        // plugin can always register handlers; the built-in Activity component is a real reactive element.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        // The bus is a DECLARED module since phase D (greenhouse decisions/0211, D1): the page LOADS it —
        // `window.MilpaShell` is still the published extension point, it is simply not written here.
        self::assertStringNotContainsString('window.MilpaShell', $body, 'the page carries no copy of the bus');
        self::assertStringContainsString('<script src="/desktop/assets/c/desktop-shell-bus.js" defer></script>', $body);
        self::assertStringContainsString('window.MilpaShell = { on: on', self::module('desktop-shell-bus'));
        self::assertStringContainsString('id="milpa-activity"', $body);
        // The Activity tab CONSUMES the bus from its own module now (greenhouse decisions/0211, B6).
        self::assertStringContainsString('window.MilpaShell.onAny(', self::module('desktop-activity'));
        self::assertStringNotContainsString('MilpaShell.onAny(', $body);
    }

    public function testAPluginPanelRendersWithItsTitleAndCardChrome(): void
    {
        // The DX (0478): a plugin declares a dashboard panel with one addPanel() call; the shell wraps it in
        // consistent card chrome with the title, and it becomes a component the plugin can drive via panel().
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->subscribe(ShellController::COMPOSE_EVENT, static function (string $eventName, array $payload): void {
            $payload['composition']->addPanel('sessions', 'Sessions', '<p class="mono" data-count>0</p>');
        });

        $body = (string) (new ShellController($dispatcher))->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('data-panel="sessions"', $body);
        self::assertStringContainsString('Sessions', $body);
        self::assertStringContainsString('mui-card__title', $body);
        self::assertStringContainsString('data-panel-body', $body);
        self::assertStringContainsString('data-count', $body);
    }

    public function testTheSettingsViewIsServedWithinTheSameShell(): void
    {
        // Settings occupies the same shell (wireframe 2c), reached from the sidebar — not a separate window.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('data-view="settings"', $body);
        self::assertStringContainsString('data-view="session"', $body);
        self::assertStringContainsString('Model and provider', $body);
        self::assertStringContainsString('Default autonomy', $body);
        self::assertStringContainsString('data-nav="settings"', $body);
        self::assertStringContainsString('data-theme-set="light"', $body);
    }

    public function testTheCapabilitiesViewShowsInstalledAndAvailable(): void
    {
        // Real backend data (0193): the catalogue shows what is installed and what is available, the same
        // answer the agent reads — installed as a section, available as another.
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [DesktopAppPlugin::class]]);
        $kernel->container()->registerService(Kernel::class, $kernel);
        $controller = new ShellController(new EventDispatcher(new NullLogger()), null, new DesktopData($kernel->container()));

        $body = (string) $controller->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('data-view="capabilities"', $body);
        self::assertStringContainsString('id="milpa-capabilities"', $body);
        self::assertStringContainsString('cap-grid', $body);
        self::assertStringContainsString('Installed ·', $body);
        self::assertStringContainsString('Available ·', $body);
    }

    public function testCapabilityCatalogueViewRendersCardsAndAOneClickEnable(): void
    {
        // Populated catalogue (pure view, greenhouse decisions/0193): an installed capability renders a card
        // with its badge; an available one renders its exact command as legible consent plus a one-click Enable.
        $html = (new CapabilityCatalogueView())->html(
            [['id' => 'agent', 'title' => 'Sessions that outlive the process', 'provides' => 'agent.sessions']],
            [['package' => 'milpa/data', 'title' => 'Persistence with four backends', 'unlocks' => ['persistence'], 'command' => 'composer require milpa/data']],
        );

        self::assertStringContainsString('Installed · 1', $html);
        self::assertStringContainsString('Available · 1', $html);
        self::assertStringContainsString('Sessions that outlive the process', $html);
        self::assertStringContainsString('mui-badge--success">installed', $html);
        self::assertStringContainsString('data-cap-enable="milpa/data"', $html);
        self::assertStringContainsString('composer require milpa/data', $html);
        self::assertStringContainsString('Unlocks: persistence', $html);
        self::assertStringContainsString('agent.sessions', $html);
    }

    public function testCapabilityCatalogueViewFallsBackToADerivedCommandAndEmptyStates(): void
    {
        // No command given → derive `composer require <package>`; empty collections → the two empty states.
        $derived = (new CapabilityCatalogueView())->html([], [['package' => 'milpa/mcp-server']]);
        self::assertStringContainsString('composer require milpa/mcp-server', $derived);
        self::assertStringContainsString('Only the catalogue', $derived);

        $empty = (new CapabilityCatalogueView())->html([['package' => 'milpa/core']], []);
        self::assertStringContainsString('milpa/core', $empty);
        self::assertStringContainsString('Everything available is installed', $empty);
    }

    public function testWithoutDataTheCapabilitiesViewShowsAnEmptyState(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('data-view="capabilities"', $body);
        self::assertStringContainsString('cap-grid', $body);
        self::assertStringContainsString('mui-empty', $body);
        // A settled (non-running) session shows no interrupted-run notice (greenhouse decisions/0196).
        // The MARKUP is the honest check now: the notice's sentence is a catalog key, and the whole catalog
        // travels in `#milpa-desktop-i18n`, so its words are in every page whether or not it is shown.
        self::assertStringNotContainsString('<div class="milpa-interrupted"', $body);
    }

    public function testTheDecisionsInboxRendersAParkedQuestionAcrossSessions(): void
    {
        // Populated inbox (pure view, greenhouse decisions/0195): a card carries the goal, the question, its
        // facts, and a link to open the session it was raised in.
        $html = (new DecisionsInboxView())->html([
            ['session' => 's-42', 'goal' => 'Publish the site', 'question' => 'Enable milpa/data?', 'operation' => 'capabilities:enable', 'reason' => 'privileged'],
        ]);

        self::assertStringContainsString('milpa-decisions-list', $html);
        self::assertStringContainsString('Enable milpa/data?', $html);
        self::assertStringContainsString('Publish the site', $html);
        self::assertStringContainsString('capabilities:enable', $html);
        self::assertStringContainsString('/desktop?session=s-42', $html);
    }

    public function testTheDecisionsInboxShowsAnEmptyStateWhenNothingIsParked(): void
    {
        $html = (new DecisionsInboxView())->html([]);

        self::assertStringContainsString('milpa-decisions-empty', $html);
        self::assertStringContainsString('No decisions to make', $html);
    }

    public function testTheSkillsViewRendersEachSkillWithWhoMayInvokeIt(): void
    {
        // Populated (pure view, greenhouse decisions/0197): a card per skill, its description, and who may
        // reach for it — the agent, the human, or both.
        $html = (new SkillsView())->html([
            ['name' => 'systematic-debugging', 'description' => 'A method for finding a bug by evidence', 'model_invocable' => true, 'user_invocable' => false],
            ['name' => 'brainstorming', 'description' => 'Frame the question before building', 'model_invocable' => true, 'user_invocable' => true],
        ]);

        self::assertStringContainsString('systematic-debugging', $html);
        self::assertStringContainsString('A method for finding a bug by evidence', $html);
        self::assertStringContainsString('agent &amp; you', $html, 'both-invocable badge');
        self::assertStringContainsString('>agent<', $html, 'agent-only badge');
    }

    public function testTheSkillsViewShowsAnEmptyStateWhenThereAreNone(): void
    {
        $html = (new SkillsView())->html([]);

        self::assertStringContainsString('mui-empty', $html);
        self::assertStringContainsString('SKILL.md', $html);
    }

    public function testTheRolesViewRendersASpecialistWithItsSkillsAndDenies(): void
    {
        // Populated (pure view, greenhouse decisions/0197): a role card with what it produces, the skills it
        // preloads, and the tools it is denied.
        $html = (new RolesView())->html([
            ['name' => 'reviewer', 'produces' => 'a review report', 'deny' => ['shell'], 'skills' => ['systematic-debugging']],
        ]);

        self::assertStringContainsString('reviewer', $html);
        self::assertStringContainsString('a review report', $html);
        self::assertStringContainsString('systematic-debugging', $html);
        self::assertStringContainsString('shell', $html);
        self::assertStringContainsString('denied', $html);
    }

    public function testTheRolesViewShowsAnEmptyStateWhenThereAreNone(): void
    {
        $html = (new RolesView())->html([]);

        self::assertStringContainsString('mui-empty', $html);
        self::assertStringContainsString('agent:role:declare', $html);
    }

    public function testTheScreenPreviewRendersAChipCarryingItsServedPath(): void
    {
        // Populated (pure view, greenhouse decisions/0197): a chip per declared screen, carrying the exact path
        // the live wire serves it at, so the preview iframe points straight there.
        $html = (new ScreenPreviewView())->html([
            ['name' => 'tasks', 'type' => 'data-table', 'served_at' => '/live/page?component=tasks'],
        ]);

        self::assertStringContainsString('data-screen-name="tasks"', $html);
        self::assertStringContainsString('data-screen-src="/live/page?component=tasks"', $html);
        self::assertStringContainsString('data-table', $html);
    }

    public function testTheScreenPreviewShowsAnEmptyStateWhenNoScreensAreDeclared(): void
    {
        $html = (new ScreenPreviewView())->html([]);

        self::assertStringContainsString('mui-empty', $html);
        self::assertStringContainsString('screen:declare', $html);
    }

    public function testTheScreensRenderRealSessionWorkAndAudit(): void
    {
        // The screens read real data (0482): sessions in the sidebar, the work board, the audit stream, counters.
        $dir = sys_get_temp_dir() . '/milpa-shell-sessions-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/s1.json', json_encode([
            'goal' => 'Audit the plugins', 'state' => 'working', 'turns' => 3, 'tool_calls' => 12,
            'work' => [['title' => 'List plugins', 'status' => 'done', 'origin' => 'planned']],
        ], JSON_THROW_ON_ERROR));
        $log = new ShellEventLog($dir . '/events.log');
        $log->append(new ShellEvent('gate.opened', ['operation' => 'capabilities.enable']));
        $data = new DesktopData(new DIContainer(), $log, $dir);

        $body = (string) (new ShellController(new EventDispatcher(new NullLogger()), null, $data))
            ->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('Audit the plugins', $body, 'session in the sidebar');
        self::assertStringContainsString('List plugins', $body, 'work board item');
        self::assertStringContainsString('gate.opened', $body, 'audit fact');
        self::assertStringContainsString('3 turns', $body, 'status counters');
        // The session was left in a running state ('working') → the interrupted-run notice shows on load
        // (greenhouse decisions/0196): a run reported as unfinished, never silently auto-resumed.
        self::assertStringContainsString('<div class="milpa-interrupted"', $body);
        self::assertStringContainsString('A prior run was interrupted', $body);
        self::assertStringContainsString('data-view="auth"', $body, 'the Auth screen');
        self::assertStringContainsString('data-pane="work"', $body);
        // The Work board is now a milpa/live component (0189); drag-drop still persists (0484):
        // draggable cards, drop columns and the persisting POST, on the component's board.
        self::assertStringContainsString('class="work-board" data-milpa-component="desktop-work-board"', $body);
        self::assertStringContainsString('data-milpa-state="work-board"', $body);
        self::assertStringContainsString('draggable="true" data-index="0"', $body);
        self::assertStringContainsString('class="work-col" data-status="done"', $body);
        // The gesture is the board's OWN module since phase D (greenhouse decisions/0211, D3): the board
        // binds the five drag events on its root, and the persisting POST lives in the module, not here.
        self::assertStringContainsString('x-data="desktopWorkBoard()"', $body);
        self::assertStringContainsString('@drop="onDrop($event)"', $body);
        self::assertStringNotContainsString("fetch('/desktop/work'", $body);
        self::assertStringContainsString("fetch(ROUTE, {", self::module('desktop-work-board'));
        self::assertStringContainsString("var ROUTE = '/desktop/work';", self::module('desktop-work-board'));

        unlink($dir . '/s1.json');
        unlink($dir . '/events.log');
        rmdir($dir);
    }

    public function testSettingsShowThePersistedEndpointAndTheWriteWiring(): void
    {
        // Persistence (0483): a saved endpoint comes back in the Settings field, and the write actions post.
        $dir = sys_get_temp_dir() . '/milpa-shell-store-' . uniqid('', true);
        mkdir($dir);
        $store = new DesktopStore($dir . '/sessions', $dir . '/settings.json');
        $store->saveSettings(['endpoint' => 'http://persisted.test/v1']);
        $data = new DesktopData(new DIContainer(), null, '', $store);

        $body = (string) (new ShellController(new EventDispatcher(new NullLogger()), null, $data))
            ->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('http://persisted.test/v1', $body, 'the persisted endpoint');
        // The writes moved with their screens (greenhouse decisions/0211, B5/B7): Save is the Settings
        // component's module, creating a session is the entry overlay's — both still POST, both guarded.
        self::assertStringContainsString("fetch('/desktop/settings'", self::module('desktop-settings'), 'save posts');
        self::assertStringContainsString("fetch('/desktop/sessions'", self::module('desktop-auth'), 'create session posts');
        self::assertStringNotContainsString("fetch('/desktop/sessions'", $body);

        unlink($dir . '/settings.json');
        rmdir($dir);
    }

    public function testTheShellWiresItsNavigationChromeAndBrand(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        // Brand: the Grano mark (13 kernels) replaces the placeholder glyph.
        self::assertSame(13, substr_count($body, 'class="g"'), 'the grain mark has 13 kernels');
        self::assertStringContainsString('milpa-grainmark', $body);
        // Search is a real input, not a dead trigger span.
        self::assertStringContainsString('id="milpa-search"', $body);
        self::assertStringContainsString('type="search"', $body);
        // New session is wired; the passkey link is addressable so it can degrade instead of 404-ing.
        self::assertStringContainsString('id="milpa-new-session"', $body);
        self::assertStringContainsString('id="milpa-enroll-link"', $body);
        // Decisions is its own view (no longer aliased to the session view).
        self::assertStringContainsString('data-view="decisions"', $body);
        // The composer mode chip opens a menu of the three modes.
        self::assertStringContainsString('id="milpa-mode-menu"', $body);
        self::assertStringContainsString('data-mode="auto"', $body);
    }

    public function testSelectingASessionLoadsItIntoTheHeaderAndMarksItActive(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-select-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/aaa11111.json', json_encode(['goal' => 'First goal'], JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/bbb22222.json', json_encode(['goal' => 'Second goal'], JSON_THROW_ON_ERROR));
        $data = new DesktopData(new DIContainer(), null, $dir);

        $request = (new ServerRequest('GET', '/desktop'))->withQueryParams(['session' => 'bbb22222']);
        $body = (string) (new ShellController(new EventDispatcher(new NullLogger()), null, $data))->shell($request)->getBody();

        // The sidebar links are clickable and post the id back; the selected one is marked active.
        self::assertStringContainsString('data-session-id="bbb22222" href="?session=bbb22222" aria-current="page"', $body);
        // The topbar header names the selected session, not the newest by default.
        self::assertStringContainsString('session bbb22222', $body);
        self::assertStringContainsString('Second goal', $body);

        unlink($dir . '/aaa11111.json');
        unlink($dir . '/bbb22222.json');
        rmdir($dir);
    }

    public function testTheComposerFieldIsAMilpaLiveComponentWhenWired(): void
    {
        // Milpa Components is the framework's official UI system (greenhouse decisions/0189): the composer's
        // text field is a real milpa/live <textarea> component — Alpine-bound, carrying a signed state envelope.
        $field = new \Milpa\DesktopApp\Live\ComposerField('sign-secret', 'csrf-secret');
        $rendered = $field->render();

        self::assertStringContainsString('milpaField', $rendered, 'the Alpine local runtime factory');
        self::assertStringContainsString('data-milpa-component="textarea"', $rendered);
        self::assertStringContainsString('application/milpa+xhtml', $rendered, 'the signed state envelope');

        // Wired into the shell, the composer hosts that component and serves the client runtime.
        $body = (string) (new ShellController(new EventDispatcher(new NullLogger()), null, null, $field))
            ->shell(new ServerRequest('GET', '/desktop'))->getBody();
        self::assertStringContainsString('data-milpa-component="textarea"', $body);
        self::assertStringContainsString('/desktop/assets/milpa-live.js', $body);
    }

    public function testTheComposerFieldValidatesOnBlurAndDeclaresTheStatusRepaint(): void
    {
        // End-to-end demo of cross-component reactivity (greenhouse evidence/0491): on blur the field
        // validates on the server and DECLARES a RenderEffect that re-paints the sibling status component.
        $field = new \Milpa\DesktopApp\Live\ComposerMessageComponent();
        $context = new \Milpa\Live\ValueObjects\ComponentContext('composer-message');
        $state = $field->mount(['name' => 'message'], $context);

        $ok = $field->handle(new \Milpa\Live\ValueObjects\InteractionRequest('composer-message', 'textarea', 'blur', $state, ['value' => 'hello world']));
        $render = null;
        foreach ($ok->effects as $effect) {
            if (($effect['type'] ?? null) === 'render') {
                $render = $effect;
            }
        }
        self::assertNotNull($render, 'blur declares a render effect');
        self::assertSame('composer-status', $render['target']);
        self::assertSame('input', $render['component']);
        self::assertStringContainsString('chars · ready', (string) $render['props']['value']);
        self::assertSame([], $ok->errors);

        $empty = $field->handle(new \Milpa\Live\ValueObjects\InteractionRequest('composer-message', 'textarea', 'blur', $state, ['value' => '   ']));
        self::assertArrayHasKey('value', $empty->errors, 'an empty value is rejected on the server');
    }

    public function testTheComposerFieldEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        // Milpa is event-driven: a component emits lifecycle events so other plugins can subscribe and
        // extend it (greenhouse decisions/0189). before_render mutates the props, after_render the HTML.
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(\Milpa\DesktopApp\Live\ComposerField::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['composer']->props['placeholder'] = 'Extended by a plugin';
        });
        $events->subscribe(\Milpa\DesktopApp\Live\ComposerField::AFTER_RENDER, static function (string $n, array $p): void {
            $p['composer']->html .= '<!-- plugin appended -->';
        });

        $html = (new \Milpa\DesktopApp\Live\ComposerField('sign', 'csrf', $events))->render();

        self::assertStringContainsString('Extended by a plugin', $html, 'the before_render subscriber changed the props');
        self::assertStringContainsString('plugin appended', $html, 'the after_render subscriber changed the html');
    }

    public function testTheComposerCarriesFloatingContextAndSessionPanels(): void
    {
        // Wireframe 3a: clean floating panels over the composer, opened by their figures, fed by real data.
        $dir = sys_get_temp_dir() . '/milpa-composer-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/s.json', json_encode(['tokens' => 8192, 'turns' => 2, 'tool_calls' => 41], JSON_THROW_ON_ERROR));
        $data = new DesktopData(new DIContainer(), null, $dir);

        $body = (string) (new ShellController(new EventDispatcher(new NullLogger()), null, $data))
            ->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('id="composer-input"', $body);
        self::assertStringContainsString('composer-panel composer-panel--context" data-panel-for="context"', $body);
        self::assertStringContainsString('composer-panel composer-panel--session" data-panel-for="session"', $body);
        self::assertStringContainsString('data-open-panel="context"', $body);
        self::assertStringContainsString('8.19K', $body, 'real token count');
        self::assertStringContainsString('mui-progress__bar', $body);
        // One border, one authority: the field inside the composer box renders seamless (no border of its
        // own) so the box is the only frame — a double border reads as double authority (Rod's doctrine).
        // The rule moved to the composer's OWN stylesheet in phase D (greenhouse decisions/0211, D5), so
        // the page declares it instead of carrying it.
        self::assertStringContainsString('<link rel="stylesheet" href="/desktop/assets/c/desktop-composer.css">', $body);
        self::assertMatchesRegularExpression(
            '/\.milpa-composer-box \.mui-textarea[^{]*\{[^}]*border:\s*0/',
            (string) file_get_contents(\dirname(__DIR__) . '/resources/components/desktop-composer/desktop-composer.css'),
        );

        unlink($dir . '/s.json');
        rmdir($dir);
    }

    public function testEmptyDataShowsEmptyStatesAcrossScreens(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('No sessions yet', $body);
        self::assertStringContainsString('No work board yet', $body);
        self::assertStringContainsString('no facts recorded yet', $body);
        self::assertStringContainsString('0 turns', $body);
    }

    public function testTheHiddenAttributeWinsOverInlineDisplay(): void
    {
        // The auth overlay, the composer's floating panels and the replay views all carry BOTH the
        // `hidden` attribute AND an inline `display:` — and inline display outranks a plain
        // `[hidden]{display:none}`, so without `!important` `hidden` is inert and the overlay covers
        // the dashboard forever ("Open workspace" never reveals it). This pins the rule that makes
        // `hidden` authoritative (greenhouse evidence/0488); the computed-display proof is in the browser.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('[hidden] { display: none !important; }', $body);
        // The overlay still declares BOTH — `hidden` in its markup, `display: grid` in its declared
        // stylesheet (greenhouse decisions/0211, B5). The stylesheet is emitted AFTER the shell's own
        // `<style>` and ties it on specificity, so only the `!important` above keeps `hidden` authoritative.
        self::assertMatchesRegularExpression('/id="milpa-auth"[^>]*hidden/', $body);
        self::assertStringContainsString('.milpa-auth { position: fixed; inset: 0; z-index: 1400; display: grid;', self::style('desktop-auth'));
        self::assertGreaterThan(
            strpos($body, '[hidden] { display: none !important; }'),
            strpos($body, '/desktop/assets/c/desktop-auth.css'),
            'the component stylesheet lands after the shell style, so order alone would lose',
        );
    }

    public function testTheConsentGateComponentIsServedHiddenAndWiredToTheCeremony(): void
    {
        // The gate is the Desktop's reason to exist (0477): a real reactive panel that renders a parked gate
        // live and points approval at the app's own same-origin passkey ceremony.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('id="milpa-gate"', $body);
        // The live fact reaches the gate's OWN module now (greenhouse decisions/0211, B3), which fills the
        // component's data — the card binds it — and builds the same-origin ceremony link.
        $gate = self::module('desktop-gate');
        self::assertStringContainsString("window.MilpaShell.on('gate.opened'", $gate);
        self::assertStringContainsString("INTENT + '?operation='", $gate);
        self::assertStringContainsString("var INTENT = '/webauthn/intent';", $gate);
        self::assertStringNotContainsString("MilpaShell.on('gate.opened'", $body);
        // The bug that used to end every parked question: a LOOKUP of a badge nothing renders, followed by
        // `badge.hidden = …`. Neither the page nor the gate's module reaches for it any more — both only
        // NAME it, in the comment that records why. (The element itself: `id="milpa-decisions-badge"` is
        // rendered by no surface of this package, which is what made the lookup a TypeError.)
        self::assertStringNotContainsString("getElementById('milpa-decisions-badge')", $body);
        self::assertStringNotContainsString("getElementById('milpa-decisions-badge')", $gate);
        self::assertStringNotContainsString('id="milpa-decisions-badge"', $body, 'nothing renders it — that was the bug');
        self::assertStringNotContainsString('badge.hidden', $gate, 'the gate does not own the decisions count');
        // Positive control: the count IS ticked, by the surface that OWNS it. Since phase D (greenhouse
        // decisions/0211, D4) that is the sidebar's own module, which consumes the transport's
        // `decision.parked` fact and finds its badge by its nav row — an element the sidebar renders when
        // a question waits. The page reaches for neither.
        $sidebar = self::module('desktop-sidebar');
        self::assertStringContainsString("querySelector('[data-nav=\"decisions\"]')", $sidebar);
        self::assertStringContainsString("querySelector('.mui-sidebar__item-badge')", $sidebar);
        self::assertStringContainsString("bus.on('decision.parked'", $sidebar);
        self::assertStringNotContainsString("querySelector('[data-nav=\"decisions\"]", $body);
    }

    public function testWithoutAHubTheShellCarriesNoConnectionOrCookie(): void
    {
        $res = $this->controller()->shell(new ServerRequest('GET', '/desktop'));

        self::assertSame('', $res->getHeaderLine('Set-Cookie'));
        // The runtime is there, but nothing connects it to a transport without a hub.
        self::assertStringNotContainsString('new EventSource(', (string) $res->getBody());
    }

    public function testWithAHubTheShellSetsTheCookieAndSubscribesOverEventSource(): void
    {
        $mercure = new MercureConfig(
            'http://hub/.well-known/mercure',
            'https://public.example/.well-known/mercure',
            'pub',
            'sub',
            'desktop/shell',
        );

        $res = $this->controller($mercure)->shell(new ServerRequest('GET', '/desktop'));
        $body = (string) $res->getBody();

        // The hub reads the subscriber JWT from this cookie.
        self::assertStringContainsString('mercureAuthorization=', $res->getHeaderLine('Set-Cookie'));
        // The hub travels as DATA now (greenhouse decisions/0211, D1): the page writes the PUBLIC url with
        // its two EXACT topics into a JSON tag, and `desktop-hub.js` is what opens the one connection — no
        // poll, no template ambiguity (greenhouse decisions/0190), and no URL baked into executable markup.
        self::assertStringNotContainsString('new EventSource(', $body, 'the page opens nothing itself');
        self::assertSame(1, preg_match('#<script id="milpa-desktop-hub" type="application/json">(.*?)</script>#s', $body, $tag));
        $hub = json_decode($tag[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($hub);
        self::assertStringStartsWith('https://public.example/.well-known/mercure?topic=desktop%2Fshell', (string) $hub['url']);
        self::assertStringContainsString('&topic=' . rawurlencode('milpa/sessions/'), (string) $hub['url']);
        // The hub cookie is scoped to the session too, and the session id is pinned in its own cookie.
        self::assertStringContainsString('milpa_agent_sid=', $res->getHeaderLine('Set-Cookie'));
        // The connection feeds the component runtime rather than dumping raw text, and a session projection
        // (`kind`) is translated to the shell's own facts — both in the module, neither in the page.
        $connector = self::module('desktop-hub');
        self::assertStringContainsString('new EventSource(URL, { withCredentials: true })', $connector);
        self::assertStringContainsString('function translate(env)', $connector);
        self::assertStringContainsString("say(env.event, env.data)", $connector);
        // …and it opens only after every DEFERRED script has run (greenhouse decisions/0211): the gate and
        // the activity stream subscribe to this bus from their component modules, which are deferred too,
        // and a fact already queued at the hub arrives inside the window between connecting and subscribing.
        self::assertSame(1, preg_match("#addEventListener\('DOMContentLoaded', function \(\) \{ open\(\); \}\);#", $connector), 'the stream opens after the modules subscribed');
    }

    public function testWithoutAHubTheOfflineStatusAlsoWaitsForTheModules(): void
    {
        // Same ordering, other branch: a Desktop with no hub says so ONCE, to handlers that exist. The page
        // says it by writing an EMPTY hub tag; the module is what reports it (greenhouse decisions/0211, D1).
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('<script id="milpa-desktop-hub" type="application/json">{}</script>', $body);
        $connector = self::module('desktop-hub');
        self::assertStringContainsString("if (URL === '' || typeof window.EventSource !== 'function') {", $connector);
        self::assertStringContainsString("b.status('offline');", $connector);
        self::assertSame(1, preg_match("#addEventListener\('DOMContentLoaded', function \(\) \{ open\(\); \}\);#", $connector));
    }

    public function testTheComposerStartsAGovernedTurnOverTheHttpSurface(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $turn = self::module('desktop-turn');
        $composer = self::module('desktop-composer');

        // The turn is a DECLARED VIEW now (greenhouse decisions/0211, phase C3): `desktop-turn.js` is the
        // one place the Desktop asks for one. Send POSTs the prompt to the governed `agent` op's HTTP route
        // with the server-minted session id (decisions/0190); the answer comes back, the badge streams over
        // the hub. The mode it sends is the chip's VALUE — asked of the composer — not a hardcoded ask.
        self::assertStringContainsString("fetch(ROUTE, {", $turn);
        self::assertStringContainsString("var ROUTE = '/agent';", $turn);
        self::assertStringContainsString('body: JSON.stringify({ prompt: text, session: SESSION, mode: bar ? bar.mode() : \'ask\' })', $turn);
        self::assertStringNotContainsString("mode: 'ask',", $turn);
        self::assertStringContainsString("return MODES.indexOf(value) !== -1 ? value : DEFAULT_MODE;", $composer, 'the composer owns the mode the turn reads');
        // The session id reaches the module as DATA, not as a value baked into a script the page runs.
        self::assertSame(1, preg_match('#<script id="milpa-desktop-session" type="application/json">(.*?)</script>#s', $body, $m));
        self::assertMatchesRegularExpression('/^\{"agent":"desk-[0-9a-f]{16}"\}$/', $m[1]);
        self::assertStringContainsString("var SESSION_TAG = 'milpa-desktop-session';", $turn);
        self::assertStringNotContainsString("var agentSession = '", $body);
        // Minimalist composer (greenhouse decisions/0191, Rod): the char count lives in the footer, live,
        // instead of a separate status line under the box — and its words come from the catalog now.
        self::assertStringContainsString('id="milpa-charcount"', $body);
        self::assertStringContainsString("count.textContent = text.length > 0 ? tr('composer.tokens', tokens) : '';", $composer);
        // The agent's answer is rendered markdown (safe subset), not raw text (greenhouse decisions/0191,
        // Rod) — and the renderer belongs to the message kind that shows it.
        self::assertStringContainsString('function renderMarkdown(', self::module('desktop-agent-message'));
        self::assertStringContainsString('body.innerHTML = renderMarkdown(opts.text', self::module('desktop-agent-message'));
        // The token counter is the provider's REAL count (greenhouse decisions/0192), not a "≈" estimate.
        self::assertStringContainsString("signal('session.tokens', kfmt(result.tokens))", $turn);
        self::assertStringContainsString("signal('context.used', kfmt(result.contextTokens))", $turn);
        self::assertStringNotContainsString('≈', $body);
        // No stray NUL bytes in the rendered shell (a corruption the code-block placeholder once introduced).
        self::assertStringNotContainsString("\0", $body);
        // The session projection maps activity thinking/ready to the working badge, message to a bubble —
        // in the transport's own module since phase D (greenhouse decisions/0211, D1).
        $connector = self::module('desktop-hub');
        self::assertStringContainsString("say('session.state', { state: 'working' })", $connector);
        self::assertStringContainsString("say('agent.message'", $connector);
    }

    public function testTheComposerServesItsCommandsAndTheModeReachesTheSession(): void
    {
        // Composer commands (greenhouse decisions/0202): the house serves the command list as JSON for the
        // parser AND renders it as the completion popup; the mode is a signal pair seeded from the saved
        // setting (the one truth on load), and every command is a governed operation reached over its http
        // projection with the method that projection answers to. Since phase C the BEHAVIOUR is
        // `desktop-commands.js` and `desktop-composer.js`; the page serves the data and the markup.
        $dir = sys_get_temp_dir() . '/milpa-shell-cmds-' . uniqid('', true);
        mkdir($dir);
        $store = new DesktopStore($dir . '/sessions', $dir . '/settings.json');
        $store->saveSettings(['mode' => 'auto']);
        $data = new DesktopData(new DIContainer(), null, '', $store);

        $body = (string) (new ShellController(new EventDispatcher(new NullLogger()), null, $data))
            ->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $commands = self::module('desktop-commands');
        $composer = self::module('desktop-composer');

        // The list the house serves: the house commands (no kernel → no skills) as JSON, each carrying the
        // METHOD of its http projection.
        self::assertMatchesRegularExpression('#<script id="milpa-commands" type="application/json">\[\{"name":"goal","kind":"house"#', $body);
        self::assertStringContainsString('"usage":"/mode ask|acknowledge|auto","method":"POST"', $body);
        self::assertStringContainsString('"name":"help","kind":"house"', $body);
        self::assertStringContainsString("var LIST_TAG = 'milpa-commands';", $commands, 'the module reads the list the page served');
        // The JSON is HEX-encoded: no `<` survives inside the script (the house's own `<text>` placeholder is
        // the specimen), so no served description can close the element — yet it decodes to the same strings.
        preg_match('#<script id="milpa-commands" type="application/json">(.*?)</script>#s', $body, $m);
        self::assertStringNotContainsString('<', $m[1]);
        self::assertStringContainsString('\\u003Ctext\\u003E', $m[1]);
        self::assertSame(DesktopData::houseCommands(), json_decode($m[1], true));
        // The completion popup is the pure CommandListView, closed until a slash is typed; the field is
        // HANDED to the module by the composer, and the module announces the popup (aria-controls) and the
        // option it highlights (aria-activedescendant on the option's id).
        self::assertStringContainsString('id="milpa-command-list" class="milpa-cmds" role="listbox" aria-label="Commands" data-open="0"', $body);
        self::assertStringContainsString('id="milpa-cmd-goal" data-command="goal" data-kind="house"', $body);
        self::assertStringContainsString("field.setAttribute('aria-controls', POPUP_ID)", $commands);
        self::assertStringContainsString("field.setAttribute('aria-activedescendant', option.id)", $commands);
        self::assertStringContainsString("if (c && typeof c.bindField === 'function') { c.bindField(field); }", $composer, 'the popup never reaches for the field');
        self::assertStringContainsString('function refresh() {', $commands);
        self::assertStringContainsString('function handlesKey(event) {', $commands);
        // Tab completes, Shift+Tab leaves the field; a click in the field does not close the popup — and the
        // click-away itself is the `ui.dismiss` signal (greenhouse decisions/0211): the popup CONSUMES it
        // instead of hanging its own document listener.
        self::assertStringContainsString("(event.key === 'Tab' && !event.shiftKey)", $commands);
        self::assertStringContainsString('guard.onDismiss(function (event) {', $commands);
        self::assertStringContainsString('if (!(field && event && event.target === field)) { hide(); }', $commands);
        foreach ([$body, $commands, $composer] as $source) {
            self::assertStringNotContainsString("document.addEventListener('click'", $source, 'the single document listener lives in the guard module');
        }
        // The mode is a signal PAIR seeded from the saved setting: the VALUE the turn sends and its label. It is
        // NOT remembered in the browser — the saved setting is the one truth on load.
        self::assertStringContainsString('"composer.mode":"auto","composer.mode.label":"Continue automatically"', $body);
        self::assertStringContainsString('<script id="milpa-live-persist" type="application/json">[]</script>', $body);
        self::assertStringNotContainsString('"composer.mode"]', $body);
        self::assertStringContainsString("signal('composer.mode', key);", $composer);
        // Only a REAL command is intercepted (a house command or a served name); a bare unknown `/name` is told;
        // anything else reaches the model as a prompt.
        self::assertStringContainsString("HOUSE.indexOf(match[1]) === -1 && !named(match[1])", $commands);
        self::assertStringContainsString('function isBareUnknown(text)', $commands);
        self::assertStringContainsString('if (c && c.isBareUnknown(text)) { return c.unknown(text); }', $composer);
        // Every command is a governed operation over its http projection, called with the method it declares —
        // no invented action. The op's OWN answer decides: `ok:false` on a 2xx is a refusal.
        self::assertStringContainsString('ok: response.ok && data.ok !== false', $commands);
        self::assertStringContainsString("call('POST', '/agent/goal', body)", $commands);
        // /goal reads the RESPONSE (goal / changed), never echoes the request — and says it from the catalog.
        self::assertStringContainsString("notice(result.data.changed === true ? tr('command.goal.cleared') : tr('command.goal.none'));", $commands);
        self::assertStringContainsString("notice(tr(result.data.changed === false ? 'command.goal.unchanged' : 'command.goal.set', goal));", $commands);
        self::assertSame('no standing goal — /goal <text> sets one', (new Catalog())->tr('command.goal.none'));
        // /mode writes the chip and the setting; the next turn carries the mode — no agent:mode call.
        self::assertStringNotContainsString('/agent/mode', $commands);
        self::assertStringContainsString('var key = command.args.toLowerCase()', $commands);
        self::assertStringContainsString("notice(tr(key === 'auto' ? 'command.mode.set.auto' : 'command.mode.set', key));", $commands);
        self::assertSame('mode auto — applies from the next turn (a signature or third-party egress still asks)', (new Catalog())->tr('command.mode.set.auto', 'auto'));
        // A skill is invoked through skill:invoke (GET) and its body enters the turn AS-IS, the args after it.
        self::assertStringContainsString("call('GET', '/skill/invoke', { name: skill.name })", $commands);
        self::assertStringNotContainsString('/skill/load', $commands);
        self::assertStringNotContainsString("by: 'human'", $commands);
        self::assertStringContainsString("t2.run(result.data.body + (command.args !== '' ? '\\n\\n' + command.args : ''))", $commands);
        self::assertStringNotContainsString('<skill_content name="', $body);
        // A refusal is never silent: the status comes back with a hint, and a 428 is reported, not confirmed.
        self::assertSame('the app does not expose agent:goal over HTTP — expose the operation in config/http.php', (new Catalog())->tr('op.hint.not_exposed', 'agent:goal'));
        self::assertStringContainsString('result.status === 428', $commands);
        self::assertStringContainsString("tr('op.refused', op, error || tr('op.no_reason'))", $commands);

        unlink($dir . '/settings.json');
        rmdir($dir);
    }
    public function testWithoutADataSeamTheComposerStillKnowsTheHouseCommands(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('"name":"goal","kind":"house"', $body);
        self::assertStringContainsString('data-command="mode" data-kind="house"', $body);
        self::assertStringContainsString('"composer.mode":"ask","composer.mode.label":"Ask before changing"', $body);
    }

    public function testReasoningStreamsIntoACollapsibleThinkingBlock(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $conversation = self::module('desktop-conversation');
        $thinking = self::module('desktop-thinking');

        // Reasoning deltas map to agent.reasoning and stream into a live thinking block (greenhouse
        // decisions/0190); the block collapses to a toggle when the turn produces its message or ends.
        self::assertStringContainsString("say('agent.reasoning'", self::module('desktop-hub'));
        self::assertStringContainsString('env.reasoning.delta', self::module('desktop-hub'));
        self::assertStringNotContainsString('env.reasoning.delta', $body, 'the translation is the transport module\'s');
        self::assertStringContainsString("shell.on('agent.reasoning', function (fact) { reasoning((fact && fact.text) || ''); });", $conversation);
        self::assertStringContainsString('function reasoning(text) {', $conversation);
        self::assertStringContainsString('function endReasoning() {', $conversation);
        // The thinking block is the `desktop-thinking` component, and since phase C its own module: the
        // conversation asks the KIND to open, feed and close itself — it clones no prototype of another's.
        self::assertStringContainsString("var PROTO_ID = 'milpa-thinking-proto';", $thinking);
        self::assertStringContainsString('proto.content.cloneNode(true)', $thinking);
        self::assertStringContainsString('[data-thinking-body]', $thinking);
        self::assertStringContainsString("block.setAttribute('data-open', '0');", $thinking);
        self::assertStringContainsString("var elapsed = tr('thinking.elapsed', seconds);", $thinking, 'the elapsed is catalog copy');
        // One delegated toggle for every thinking block, now and future — the conversation dispatches it.
        self::assertStringContainsString("event.target.closest('[data-thinking-toggle]')", $thinking);
        self::assertStringContainsString('@click="onClick($event)"', $body, 'the thread binds ONE click');
        self::assertStringContainsString('function dispatch(event) {', $conversation);
        // The prototype is present, a real component with its declared behaviour and signed envelope.
        self::assertStringContainsString('<template id="milpa-thinking-proto">', $body);
        self::assertStringContainsString('data-milpa-component="desktop-thinking"', $body);
        // The agent's message and the turn ending both close the block.
        self::assertStringContainsString("shell.on('agent.message', function (fact) { endReasoning();", $conversation);
        self::assertStringContainsString("if (conv && !(fact && fact.state === 'working')) { conv.endReasoning(); }", self::module('desktop-turn'));
    }

    public function testEveryDesktopFetchPassesThroughOneGuardAndTheGuardIsItsOwnModule(): void
    {
        // The Desktop stands behind the same door as the admin (greenhouse decisions/0209): a gate may answer any
        // call instead of the handler, so ONE helper reads every fetch() result — a 401 with `signin` leaves for
        // sign-in and comes back (`next`), a 403 is told once, any other non-2xx rejects with its status.
        // Since the declared views (greenhouse decisions/0211, A4) that helper is a RUNTIME MODULE, not a
        // function in the page: `desktop-guard.js`, hung off `MilpaLive.desktop`, so every component module
        // reaches the same one. The page delegates and never carries a second copy.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $guard = self::module('desktop-guard');

        // The behaviour is in the module…
        self::assertStringContainsString('function guarded(r) {', $guard);
        // Every 401 is the door, not only one that names itself: the body's `signin` when it has one, else
        // the app's declared sign-in path from `#milpa-desktop-guard` (greenhouse decisions/0211 review).
        self::assertStringContainsString("var signin = r.status === 401 ? signinFor(body) : '';", $guard);
        self::assertStringContainsString("if (body && typeof body.signin === 'string' && body.signin !== '') { return body.signin; }", $guard);
        self::assertStringContainsString("return typeof DOORS.signin === 'string' ? DOORS.signin : '';", $guard);
        self::assertStringContainsString("location.assign(signin + '?next=' + encodeURIComponent(location.pathname + location.search))", $guard);
        self::assertStringContainsString('return new Promise(function () {});', $guard, 'a page that is leaving settles nothing');
        self::assertStringContainsString('if (r.status === 403) {', $guard);
        self::assertStringContainsString("tr('guard.forbidden.reason', body.error) : tr('guard.forbidden')", $guard);
        self::assertStringContainsString('return Promise.reject(err);', $guard);
        self::assertStringContainsString('function guardedFlow(r) {', $guard, 'the capabilities 428 passthrough is the module\'s too');
        self::assertStringContainsString('function failed(err, unreachable) {', $guard);
        self::assertStringContainsString('live.desktop = {', $guard, 'it hangs off the framework runtime');
        // One `%s` per argument (greenhouse decisions/0211, phase C): the copy the commands say carries three.
        self::assertStringContainsString('for (var i = 0; i < args.length; i++) { s = String(s).replace(\'%s\', String(args[i])); }', $guard);

        // …and NOT in the page: what the page keeps is a one-line delegation per name, nothing more.
        foreach (['function guarded(r) {', 'r.status === 428 ? r : guarded(r)', 'if (err && err.told) { return; }', 'var I18N = (function () {'] as $moved) {
            self::assertStringNotContainsString($moved, $body, 'moved to desktop-guard.js: ' . $moved);
        }
        // Since phase D the page keeps no shim either: there is nothing left in it to delegate FROM
        // (greenhouse decisions/0211). Every caller reaches the guard through `MilpaLive.desktop` from its
        // own module, and each of those modules fails closed the same way.
        foreach (['function failed(err, unreachable) { var d = desk();', 'function tr(key, arg) { var d = desk();', 'function guard(r) { var d = desk();'] as $shim) {
            self::assertStringNotContainsString($shim, $body, 'the page has no caller left to shim: ' . $shim);
        }
        foreach (['desktop-capabilities', 'desktop-work-board', 'desktop-turn'] as $caller) {
            self::assertStringContainsString('function desk() { return live.desktop || null; }', self::module($caller), $caller . ' resolves the guard on use');
        }
        // The page loads it, once, through LiveBoot — before any component module, after the runtime.
        self::assertSame(1, substr_count($body, '<script src="/desktop/assets/c/desktop-guard.js" defer></script>'));
        self::assertLessThan(strpos($body, '/desktop/assets/alpine.min.js'), strpos($body, '/desktop/assets/c/desktop-guard.js'));
        self::assertGreaterThan(strpos($body, '/desktop/assets/milpa-live-remote.js'), strpos($body, '/desktop/assets/c/desktop-guard.js'));

        // Every call site FAILS CLOSED: with no guard loaded a call is refused, never passed through unread.
        foreach (['desktop-capabilities', 'desktop-work-board', 'desktop-turn'] as $caller) {
            self::assertStringContainsString("return Promise.reject(new Error('desktop-guard not loaded'));", self::module($caller), $caller . ' fails closed');
        }
        // The writes that moved with their surfaces keep the SAME discipline, in their own modules.
        self::assertStringContainsString("}).then(d.guarded).then(function () {\n          location.reload();", self::module('desktop-auth'), 'POST /desktop/sessions');
        self::assertStringContainsString("}).then(d.guarded).then(function () {\n          self.report(true, tr('settings.saved'));", self::module('desktop-settings'), 'POST /desktop/settings — Saved only on a 2xx');
        self::assertStringContainsString("self.report(false, tr('settings.save_failed', (err && err.status) || 0));", self::module('desktop-settings'));
        self::assertStringContainsString('.then(d.guardedFlow)', self::module('desktop-capabilities'), 'capabilities step one: the confirm gate passes, a door does not');
        self::assertStringContainsString('}).then(d.guarded).then(function (confirmed) {', self::module('desktop-capabilities'), 'capabilities step two');
        self::assertStringContainsString('}).then(d.guarded).catch(function (err) { d.failed(err, tr(\'guard.unreachable\')); });', self::module('desktop-work-board'), 'POST /desktop/work');
        self::assertStringContainsString('}).then(d.guarded).then(function (response) {', self::module('desktop-turn'), 'POST /agent');
        self::assertStringContainsString("}).catch(function (err) { working(false); d.failed(err, tr('guard.unreachable')); });", self::module('desktop-turn'), 'the turn\'s unreachable copy comes from the catalog like its siblings, and a failed turn is not left «working»');
        self::assertStringNotContainsString("'The turn could not be reached.'", $body);
        self::assertStringContainsString('return request.then(d.guarded).then(read)', self::module('desktop-commands'), 'callOp → /agent/goal, /skill/invoke');
        self::assertStringContainsString('if (!result.told) { notice(failure(\'agent:goal\', result)); }', self::module('desktop-commands'), 'a refusal the guard told is not told twice');
        // NOT ONE fetch is left in the page (greenhouse decisions/0211, phase D): the capabilities two-step
        // and the work board's move were the last two, and they left with their surfaces. The claim is not
        // «fewer»; it is zero, and the modules below are where every call now lives.
        self::assertSame(0, preg_match_all('/\bfetch\((?!\))/', $body), 'the page calls nothing');
        // And in the modules, every fetch is guarded — `d.guarded` is the same discipline by another name.
        // The commands module makes TWO calls (a GET read and a POST mutation) through ONE guarded `request`;
        // the capabilities module makes two because the house's confirm gate is a two-STEP, not two calls.
        foreach (['desktop-auth' => 1, 'desktop-settings' => 1, 'desktop-sidebar' => 1, 'desktop-turn' => 1, 'desktop-composer' => 1, 'desktop-commands' => 2, 'desktop-capabilities' => 2, 'desktop-work-board' => 1] as $component => $calls) {
            $module = self::module($component);
            self::assertSame($calls, preg_match_all('/\bfetch\((?!\))/', $module), $component);
            self::assertGreaterThanOrEqual(1, substr_count($module, '.then(d.guarded)'), $component . ' guards every call');
            self::assertStringContainsString('function desk() { return live.desktop || null; }', $module, $component . ' resolves the shared guard');
            self::assertStringNotContainsString('function guarded(', $module, $component . ' carries no guard of its own');
        }
        self::assertStringContainsString(
            ".then(d.guardedFlow)\n      .then(function (response) { return response.json(); })",
            self::module('desktop-capabilities'),
            'the capabilities body is read only after the guard',
        );
        self::assertStringNotContainsString("badge.hidden = false; setTimeout", $body, 'the old unconditional Saved is gone');
    }

    public function testTheGuardSaysWhatHappenedInsteadOfReachingIntoTheConversation(): void
    {
        // A3 (greenhouse decisions/0211): `notice()` no longer calls appendMessage — it emits the
        // `desktop.notice` signal (kind, text) and the conversation CONSUMES it. Phase C took that seam:
        // the consumer is the thread's own module, so the page holds neither half of the coupling.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $guard = self::module('desktop-guard');
        $conversation = self::module('desktop-conversation');

        self::assertStringContainsString("signal('desktop.notice', payload)", $guard);
        self::assertStringContainsString("d.onNotice(function (notice) { append('system', { text: (notice && notice.text) || '' }); });", $conversation);
        self::assertStringNotContainsString('function notice(', $body, 'the page says nothing of its own any more');
        self::assertStringNotContainsString('appendMessage', $body);
        self::assertStringContainsString('"desktop.notice":null', $body, 'the signal is seeded so the store is reactive on it');
    }

    public function testWorkingIsASignalTheSendButtonAndTheTopbarBadgeBindTo(): void
    {
        // A3 (greenhouse decisions/0211): setting `session.working` is what makes the turn visible; nothing
        // pokes the button's glyph, label or disabled, nor the badge's className. `composer.draft` is the
        // other half of the button's state, so `disabled` is a binding too. Since phase C the writer is the
        // TURN's module (the turn is what works) and the composer's Stop asks it to stop.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString("function working(on) {\n    signal('session.working', !!on);", self::module('desktop-turn'));
        self::assertStringContainsString(":disabled=\"!\$store.milpa['session.working'] && !\$store.milpa['composer.draft']\"", $body);
        self::assertStringContainsString("x-text=\"\$store.milpa['session.working'] ? '■' : '↑'\"", $body);
        self::assertStringContainsString('@click="working ? stop() : send()"', $body, 'the button asks its own component');
        self::assertStringContainsString("return this.\$store.milpa['session.working'] === true;", self::module('desktop-composer'));
        // The badge asks its own component (greenhouse decisions/0211, B2): `working` is the signal, read
        // inside the effect by the `desktopTopbar` factory, so the binding stays reactive and the markup
        // stops reaching into the store.
        self::assertStringContainsString(":class=\"{ 'mui-badge--accent': working, 'mui-badge--dot': working }\"", $body);
        self::assertStringContainsString("return this.\$store.milpa['session.working'] === true;", self::module('desktop-topbar'));
        self::assertStringContainsString('"session.working":false', $body);
        self::assertStringNotContainsString('sendBtn.textContent = working', $body);
        self::assertStringNotContainsString('top.className = working', $body);
    }

    public function testTheComposerIsClearedThroughItsComponentNotASyntheticEvent(): void
    {
        // A3 (greenhouse decisions/0211): the field is a milpa/live component, so clearing it is an
        // interaction with the milpaField data (`reset('')` / `change(text)`) — the synthetic `input` event
        // that announced a keystroke nobody typed is gone. Phase C moved it into the composer's own module,
        // which reads the field from its OWN root instead of the document.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $composer = self::module('desktop-composer');

        self::assertStringContainsString("if (text === '') { data.reset(''); } else { data.change(text); }", $composer);
        self::assertStringContainsString('window.Alpine.$data(root)', $composer);
        self::assertStringContainsString("this.setText('');", $composer, 'send clears through the component');
        self::assertStringContainsString("bar.setText('/' + name + ' ');", self::module('desktop-commands'), 'a completion fills through the component');
        self::assertStringNotContainsString("dispatchEvent(new Event('input'", $composer);
        self::assertStringNotContainsString("dispatchEvent(new Event('input'", $body);
        self::assertStringContainsString('x-data="desktopComposer()"', $body, 'the bar is the component that owns the field');
    }

    public function testTheTopbarSaysWhoTheGateLetInAndWhichGateStands(): void
    {
        // Who is signed in is whatever the gate left on the request (greenhouse decisions/0209): a fake
        // `milpa.auth` context with isAuthenticated() and ->actor->id shows the chip; nothing shows none.
        $signedIn = (new ServerRequest('GET', '/desktop'))->withAttribute(RequestPrincipal::ATTRIBUTE, PasskeyGateStub::context('passkey:rod'));
        $body = (string) $this->controller()->shell($signedIn)->getBody();
        self::assertStringContainsString('signed in as passkey:rod', $body);
        self::assertStringContainsString('data-principal="passkey:rod"', $body);
        self::assertStringContainsString('data-gate="loopback"', $body, 'the default door');

        // The catalog JSON carries the phrase's template, so the chip's own attribute is what tells presence apart.
        $anonymous = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        self::assertStringNotContainsString('data-principal=', $anonymous);
        self::assertStringNotContainsString('desktop-chip--principal', $anonymous);
        self::assertStringContainsString('data-gate="loopback"', $anonymous);

        $unauthenticated = (new ServerRequest('GET', '/desktop'))->withAttribute(RequestPrincipal::ATTRIBUTE, new \stdClass());
        self::assertStringNotContainsString('data-principal=', (string) $this->controller()->shell($unauthenticated)->getBody(), 'a context that cannot say it is authenticated is nobody');

        // The gate chip follows the judged settings the plugin hands the shell.
        $open = new ShellController(new EventDispatcher(new NullLogger()), settings: new DesktopSettings(middleware: []));
        self::assertStringContainsString('data-gate="open"', (string) $open->shell(new ServerRequest('GET', '/desktop'))->getBody());
    }

    public function testTheShellSpeaksTheDeclaredLocale(): void
    {
        // English by default: the badge says Saved, and the client gets the same words as JSON.
        $en = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        self::assertStringContainsString('id="milpa-settings-saved"', $en);
        self::assertStringContainsString('>Saved</span>', $en);
        preg_match('#<script id="milpa-desktop-i18n" type="application/json">(.*?)</script>#s', $en, $m);
        self::assertNotEmpty($m, 'the catalog rides the page');
        self::assertStringNotContainsString('<', $m[1], 'no message can close the script element');
        $i18n = json_decode($m[1], true);
        self::assertSame('Saved', $i18n['settings.saved']);
        self::assertSame('Not allowed here (%s)', $i18n['guard.forbidden.reason']);
        // The guard module reads the JSON by id when it EXECUTES (LiveBoot emits it deferred, so the whole
        // document is parsed by then) — the ordering that matters is that the catalog is in the document.
        self::assertStringContainsString("document.getElementById('milpa-desktop-i18n')", (string) file_get_contents(\dirname(__DIR__) . '/resources/components/desktop-guard/desktop-guard.js'));

        // Spanish declared: the same page, the same keys, the other words.
        $es = new ShellController(new EventDispatcher(new NullLogger()), settings: new DesktopSettings(locale: 'es'));
        $body = (string) $es->shell(new ServerRequest('GET', '/desktop'))->getBody();
        self::assertStringContainsString('>Guardado</span>', $body);
        self::assertStringContainsString('"settings.saved":"Guardado"', $body);
        self::assertStringContainsString('puerta: loopback', $body, 'the topbar chip too');

        $explicit = new ShellController(new EventDispatcher(new NullLogger()), catalog: new Catalog('es'));
        self::assertStringContainsString('>Guardado</span>', (string) $explicit->shell(new ServerRequest('GET', '/desktop'))->getBody(), 'a catalog given directly wins');
    }
}
