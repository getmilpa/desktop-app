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

        // Same-origin links: the whole point of serving the shell over HTTP.
        self::assertStringContainsString('/webauthn/enroll', $body);
        self::assertStringContainsString('/webauthn/intent', $body);
    }

    public function testTheClientRuntimeAndActivityComponentAreAlwaysServed(): void
    {
        // The component runtime is the reactive-renderer contract (0476): present with or without a hub, so a
        // plugin can always register handlers; the built-in Activity component is a real reactive element.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('window.MilpaShell', $body);
        self::assertStringContainsString('MilpaShell.onAny(', $body);
        self::assertStringContainsString('id="milpa-activity"', $body);
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
        // A settled (non-running) session shows no interrupted-run notice (greenhouse decisions/0196). The
        // notice's TEXT — not its CSS class, which is always in the stylesheet — is the honest check.
        self::assertStringNotContainsString('A prior run was interrupted', $body);
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
        self::assertStringContainsString('milpa-interrupted', $body);
        self::assertStringContainsString('A prior run was interrupted', $body);
        self::assertStringContainsString('data-view="auth"', $body, 'the Auth screen');
        self::assertStringContainsString('data-pane="work"', $body);
        // The Work board is now a milpa/live component (0189); drag-drop still persists (0484):
        // draggable cards, drop columns and the persisting POST, on the component's board.
        self::assertStringContainsString('class="work-board" data-milpa-component="desktop-work-board"', $body);
        self::assertStringContainsString('data-milpa-state="work-board"', $body);
        self::assertStringContainsString('draggable="true" data-index="0"', $body);
        self::assertStringContainsString('class="work-col" data-status="done"', $body);
        self::assertStringContainsString("fetch('/desktop/work'", $body);

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
        self::assertStringContainsString("fetch('/desktop/settings'", $body, 'save posts');
        self::assertStringContainsString("fetch('/desktop/sessions'", $body, 'create session posts');

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
        self::assertStringContainsString('composer-panel" data-panel-for="context"', $body);
        self::assertStringContainsString('composer-panel" data-panel-for="session"', $body);
        self::assertStringContainsString('data-open-panel="context"', $body);
        self::assertStringContainsString('8.19K', $body, 'real token count');
        self::assertStringContainsString('mui-progress__bar', $body);
        // One border, one authority: the field inside the composer box renders seamless (no border of its
        // own) so the box is the only frame — a double border reads as double authority (Rod's doctrine).
        self::assertMatchesRegularExpression('/\.milpa-composer-box \.mui-textarea[^{]*\{[^}]*border:\s*0/', $body);

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
        // The overlay still declares its own display AND hidden — the rule above is what reconciles them.
        self::assertMatchesRegularExpression('/id="milpa-auth"[^>]*hidden[^>]*display:grid/', $body);
    }

    public function testTheConsentGateComponentIsServedHiddenAndWiredToTheCeremony(): void
    {
        // The gate is the Desktop's reason to exist (0477): a real reactive panel that renders a parked gate
        // live and points approval at the app's own same-origin passkey ceremony.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('id="milpa-gate"', $body);
        self::assertStringContainsString("MilpaShell.on('gate.opened'", $body);
        // Approval is built as a link to the same-origin intent ceremony from the event's operation.
        self::assertStringContainsString("'/webauthn/intent?operation='", $body);
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
        // The client subscribes to the hub's PUBLIC url on the shell topic AND this session's EXACT stream
        // topic on ONE connection — no poll, no template ambiguity (greenhouse decisions/0190).
        self::assertStringContainsString('new EventSource(', $body);
        self::assertStringContainsString('https://public.example/.well-known/mercure?topic=desktop%2Fshell', $body);
        self::assertStringContainsString('&topic=' . rawurlencode('milpa/sessions/'), $body);
        // The hub cookie is scoped to the session too, and the session id is pinned in its own cookie.
        self::assertStringContainsString('milpa_agent_sid=', $res->getHeaderLine('Set-Cookie'));
        // The connection feeds the component runtime rather than dumping raw text.
        self::assertStringContainsString('MilpaShell.emit(', $body);
        // A session projection (kind) is translated to the shell's own events by MilpaShell.session.
        self::assertStringContainsString('MilpaShell.session(env)', $body);
    }

    public function testTheComposerStartsAGovernedTurnOverTheHttpSurface(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        // Send POSTs the prompt to the governed `agent` op's HTTP route with the server-minted session id
        // (greenhouse decisions/0190); the answer comes back, the badge streams over the hub. The mode it
        // sends is the chip's VALUE — the `composer.mode` signal — not a hardcoded ask (decisions/0202).
        self::assertStringContainsString("fetch('/agent'", $body);
        self::assertStringContainsString('session: agentSession', $body);
        self::assertStringNotContainsString("mode: 'ask'", $body);
        self::assertStringContainsString('mode: currentMode()', $body);
        self::assertStringContainsString("MilpaLive.signal('composer.mode')", $body);
        self::assertStringContainsString("var agentSession = '", $body);
        // Minimalist composer (greenhouse decisions/0191, Rod): the char count lives in the footer, live,
        // instead of a separate status line under the box.
        self::assertStringContainsString('id="milpa-charcount"', $body);
        self::assertStringContainsString("charCount.textContent = n > 0 ? ('~' + toks + ' tokens') : ''", $body);
        // The agent's answer is rendered markdown (safe subset), not raw text (greenhouse decisions/0191, Rod).
        self::assertStringContainsString('function renderMarkdown(', $body);
        self::assertStringContainsString('b.innerHTML = renderMarkdown(o.text', $body);
        // The token counter is the provider's REAL count (greenhouse decisions/0192), not a "≈" estimate.
        self::assertStringContainsString("setSig('session.tokens', kfmt(res.tokens))", $body);
        self::assertStringContainsString("setSig('context.used', kfmt(res.contextTokens))", $body);
        self::assertStringNotContainsString('≈', $body);
        // No stray NUL bytes in the rendered shell (a corruption the code-block placeholder once introduced).
        self::assertStringNotContainsString("\0", $body);
        // The session projection maps activity thinking/ready to the working badge, message to a bubble.
        self::assertStringContainsString("this.emit('session.state', { state: 'working' })", $body);
        self::assertStringContainsString("this.emit('agent.message'", $body);
    }

    public function testTheComposerServesItsCommandsAndTheModeReachesTheSession(): void
    {
        // Composer commands (greenhouse decisions/0202): the house serves the command list as JSON for the
        // parser AND renders it as the completion popup; the mode is a signal pair seeded from the saved
        // setting (the one truth on load), and every command is a governed operation reached over its http
        // projection with the method that projection answers to.
        $dir = sys_get_temp_dir() . '/milpa-shell-cmds-' . uniqid('', true);
        mkdir($dir);
        $store = new DesktopStore($dir . '/sessions', $dir . '/settings.json');
        $store->saveSettings(['mode' => 'auto']);
        $data = new DesktopData(new DIContainer(), null, '', $store);

        $body = (string) (new ShellController(new EventDispatcher(new NullLogger()), null, $data))
            ->shell(new ServerRequest('GET', '/desktop'))->getBody();

        // The list the house serves: the house commands (no kernel → no skills) as JSON, before the shell script,
        // each carrying the METHOD of its http projection.
        self::assertMatchesRegularExpression('#<script id="milpa-commands" type="application/json">\[\{"name":"goal","kind":"house"#', $body);
        self::assertStringContainsString('"usage":"/mode ask|acknowledge|auto","method":"POST"', $body);
        self::assertStringContainsString('"name":"help","kind":"house"', $body);
        self::assertLessThan(strpos($body, "function parseCommand("), strpos($body, 'id="milpa-commands"'), 'the JSON precedes the script that reads it');
        // The JSON is HEX-encoded: no `<` survives inside the script (the house's own `<text>` placeholder is
        // the specimen), so no served description can close the element — yet it decodes to the same strings.
        preg_match('#<script id="milpa-commands" type="application/json">(.*?)</script>#s', $body, $m);
        self::assertStringNotContainsString('<', $m[1]);
        self::assertStringContainsString('\\u003Ctext\\u003E', $m[1]);
        self::assertSame(DesktopData::houseCommands(), json_decode($m[1], true));
        // The completion popup is the pure CommandListView, closed until a slash is typed; the field announces
        // it (aria-controls) and the highlighted option (aria-activedescendant on the option's id).
        self::assertStringContainsString('id="milpa-command-list" class="milpa-cmds" role="listbox" aria-label="Commands" data-open="0"', $body);
        self::assertStringContainsString('id="milpa-cmd-goal" data-command="goal" data-kind="house"', $body);
        self::assertStringContainsString("composerInput.setAttribute('aria-controls', 'milpa-command-list')", $body);
        self::assertStringContainsString("composerInput.setAttribute('aria-activedescendant', opt.id)", $body);
        self::assertStringContainsString('function refreshCommandList(', $body);
        self::assertStringContainsString('function commandListHandlesKey(', $body);
        // Tab completes, Shift+Tab leaves the field; a click in the field does not close the popup.
        self::assertStringContainsString("(e.key === 'Tab' && !e.shiftKey)", $body);
        self::assertStringContainsString('if (!(composerInput && e.target === composerInput)) { cmdHide(); }', $body);
        // The mode is a signal PAIR seeded from the saved setting: the VALUE the turn sends and its label. It is
        // NOT remembered in the browser — the saved setting is the one truth on load.
        self::assertStringContainsString('"composer.mode":"auto","composer.mode.label":"Continue automatically"', $body);
        self::assertStringContainsString('<script id="milpa-live-persist" type="application/json">[]</script>', $body);
        self::assertStringNotContainsString('"composer.mode"]', $body);
        self::assertStringContainsString("setSig('composer.mode', key)", $body);
        // Only a REAL command is intercepted (a house command or a served name); a bare unknown `/name` is told;
        // anything else reaches the model as a prompt.
        self::assertStringContainsString("HOUSE_COMMANDS.indexOf(m[1]) === -1 && !commandNamed(m[1])", $body);
        self::assertStringContainsString('function isBareUnknownCommand(', $body);
        self::assertStringContainsString('if (isBareUnknownCommand(text))', $body);
        // Every command is a governed operation over its http projection, called with the method it declares —
        // no invented action. The op's OWN answer decides: `ok:false` on a 2xx is a refusal.
        self::assertStringContainsString('ok: r.ok && d.ok !== false', $body);
        self::assertStringContainsString("callOp('POST', '/agent/goal', body)", $body);
        // /goal reads the RESPONSE (goal / changed), never echoes the request.
        self::assertStringContainsString("'no standing goal — /goal <text> sets one'", $body);
        self::assertStringContainsString("d.changed === false ? 'goal unchanged: ' : 'goal set: '", $body);
        // /mode writes the chip and the setting; the next turn carries the mode — no agent:mode call.
        self::assertStringNotContainsString('/agent/mode', $body);
        self::assertStringContainsString('var key = cmd.args.toLowerCase()', $body);
        self::assertStringContainsString("'mode ' + key + ' — applies from the next turn'", $body);
        // A skill is invoked through skill:invoke (GET) and its body enters the turn AS-IS, the args after it.
        self::assertStringContainsString("callOp('GET', '/skill/invoke', { name: skill.name })", $body);
        self::assertStringNotContainsString('/skill/load', $body);
        self::assertStringNotContainsString("by: 'human'", $body);
        self::assertStringContainsString("runTurn(d.body + (cmd.args !== '' ? '\\n\\n' + cmd.args : ''))", $body);
        self::assertStringNotContainsString('<skill_content name="', $body);
        // A refusal is never silent: the status comes back with a hint, and a 428 is reported, not confirmed.
        self::assertStringContainsString('expose the operation in config/http.php', $body);
        self::assertStringContainsString('res.status === 428', $body);
        self::assertStringContainsString("op + ' refused — '", $body);

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

        // Reasoning deltas map to agent.reasoning and stream into a live thinking block (greenhouse
        // decisions/0190); the block collapses to a toggle when the turn produces its message or ends.
        self::assertStringContainsString("this.emit('agent.reasoning'", $body);
        self::assertStringContainsString("env.reasoning.delta", $body);
        self::assertStringContainsString("window.MilpaShell.on('agent.reasoning'", $body);
        self::assertStringContainsString('function appendReasoning(', $body);
        self::assertStringContainsString('function endReasoning(', $body);
        // The thinking block is the `desktop-thinking` component: the conversation CLONES its prototype and
        // feeds it by events (greenhouse decisions/0191) — not createElement.
        self::assertStringContainsString("getElementById('milpa-thinking-proto')", $body);
        self::assertStringContainsString('content.cloneNode(true)', $body);
        self::assertStringContainsString('[data-thinking-body]', $body);
        self::assertStringContainsString("setAttribute('data-open', '0')", $body);
        // One delegated toggle for every thinking block, now and future.
        self::assertStringContainsString('[data-thinking-toggle]', $body);
        // The prototype is present, a real component with its declared behaviour and signed envelope.
        self::assertStringContainsString('<template id="milpa-thinking-proto">', $body);
        self::assertStringContainsString('data-milpa-component="desktop-thinking"', $body);
        // The agent's message and the turn ending both close the block.
        self::assertStringContainsString("on('agent.message', function (d) { endReasoning();", $body);
    }

    public function testEveryDesktopFetchPassesThroughOneGuard(): void
    {
        // The Desktop stands behind the same door as the admin (greenhouse decisions/0209): a gate may answer any
        // call instead of the handler, so ONE helper reads every fetch() result — a 401 with `signin` leaves for
        // sign-in and comes back (`next`), a 403 is told once, any other non-2xx rejects with its status.
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('function guarded(r) {', $body);
        self::assertStringContainsString("if (r.status === 401 && typeof body.signin === 'string' && body.signin !== '')", $body);
        self::assertStringContainsString("location.assign(body.signin + '?next=' + encodeURIComponent(location.pathname + location.search))", $body);
        self::assertStringContainsString('return new Promise(function () {});', $body, 'a page that is leaving settles nothing');
        self::assertStringContainsString('if (r.status === 403) {', $body);
        self::assertStringContainsString("tr('guard.forbidden.reason', body.error) : tr('guard.forbidden')", $body);
        self::assertStringContainsString('return Promise.reject(err);', $body);

        // Every call site the reader mapped goes through it.
        self::assertStringContainsString("}).then(guarded).then(function () { location.reload(); })", $body, 'POST /desktop/sessions');
        self::assertStringContainsString("}).then(guarded).then(function () {\n          showSaved(true, tr('settings.saved'));", $body, 'POST /desktop/settings — Saved only on a 2xx');
        self::assertStringContainsString("showSaved(false, tr('settings.save_failed', (err && err.status) || 0));", $body);
        self::assertStringContainsString("fetch(url, { method: 'POST', headers: hdr, body: body }).then(function (r) { return r.status === 428 ? r : guarded(r); })", $body, 'capabilities step one: the confirm gate passes, a door does not');
        self::assertStringContainsString("fetch(url, { method: 'POST', headers: h2, body: body }).then(guarded)", $body, 'capabilities step two');
        self::assertStringContainsString("}).then(guarded).then(function (r) { return r.json(); }).then(function (res) {", $body, 'POST /agent');
        self::assertStringContainsString("}).catch(function (err) { failed(err, tr('guard.unreachable')); });", $body, 'the turn\'s unreachable copy comes from the catalog like its siblings');
        self::assertStringNotContainsString("'The turn could not be reached.'", $body);
        self::assertStringContainsString('return req.then(guarded).then(read)', $body, 'callOp → /agent/goal, /skill/invoke');
        self::assertStringContainsString("if (!res.ok) { if (!res.told) { notice(opFailure('agent:goal', res)); } return; }", $body, 'a refusal the guard told is not told twice');
        self::assertSame(2, substr_count($body, "}).then(guarded).catch(function (err) { failed(err, tr('guard.unreachable')); });"), 'POST /desktop/work and the mode chip\'s settings post');
        self::assertStringContainsString("fetch('/webauthn/enroll', { method: 'GET' }).then(guarded)", $body, 'the enrol probe');
        self::assertStringContainsString("if (err && err.status && err.status !== 404) { failed(err); return; }", $body, 'a 401/403 there is a door, not a missing one');
        self::assertStringContainsString("enroll.textContent = tr('enroll.none');", $body);
        self::assertStringNotContainsString("enroll.textContent = 'No passkey door", $body);
        // Ten fetch calls (a `fetch(` with an argument — the comments' `fetch()` is not one), and not one reads
        // its result before the guard did: eight `.then(guarded)` (callOp guards two fetches through one `req`),
        // plus the capabilities' inline `guarded(r)`.
        self::assertSame(10, preg_match_all('/\bfetch\((?!\))/', $body));
        self::assertSame(8, substr_count($body, '.then(guarded)'));
        self::assertStringContainsString("guarded(r); }).then(function (r) { return r.json(); }).then(function (a) {", $body, 'the capabilities body is read only after the guard');
        self::assertStringNotContainsString("badge.hidden = false; setTimeout", $body, 'the old unconditional Saved is gone');
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
        self::assertStringContainsString('id="milpa-settings-saved" class="mui-badge mui-badge--success" hidden>Saved</span>', $en);
        preg_match('#<script id="milpa-desktop-i18n" type="application/json">(.*?)</script>#s', $en, $m);
        self::assertNotEmpty($m, 'the catalog rides the page');
        self::assertStringNotContainsString('<', $m[1], 'no message can close the script element');
        $i18n = json_decode($m[1], true);
        self::assertSame('Saved', $i18n['settings.saved']);
        self::assertSame('Not allowed here (%s)', $i18n['guard.forbidden.reason']);
        self::assertLessThan(strpos($en, 'function guarded(r)'), strpos($en, 'id="milpa-desktop-i18n"'), 'the JSON precedes the script that reads it');

        // Spanish declared: the same page, the same keys, the other words.
        $es = new ShellController(new EventDispatcher(new NullLogger()), settings: new DesktopSettings(locale: 'es'));
        $body = (string) $es->shell(new ServerRequest('GET', '/desktop'))->getBody();
        self::assertStringContainsString('hidden>Guardado</span>', $body);
        self::assertStringContainsString('"settings.saved":"Guardado"', $body);
        self::assertStringContainsString('puerta: loopback', $body, 'the topbar chip too');

        $explicit = new ShellController(new EventDispatcher(new NullLogger()), catalog: new Catalog('es'));
        self::assertStringContainsString('hidden>Guardado</span>', (string) $explicit->shell(new ServerRequest('GET', '/desktop'))->getBody(), 'a catalog given directly wins');
    }
}
