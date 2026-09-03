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

    public function testTheCapabilitiesViewShowsRealInstalledPlugins(): void
    {
        // Real backend data (0481): the capabilities table lists the booted plugins, read from the runtime.
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [DesktopAppPlugin::class]]);
        $kernel->container()->registerService(Kernel::class, $kernel);
        $controller = new ShellController(new EventDispatcher(new NullLogger()), null, new DesktopData($kernel->container()));

        $body = (string) $controller->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('data-view="capabilities"', $body);
        self::assertStringContainsString('id="milpa-capabilities"', $body);
        // The real DesktopApp plugin appears in the table (name + version + type from its #[PluginMetadata]).
        self::assertStringContainsString('DesktopApp', $body);
        self::assertStringContainsString('mui-table__lead', $body);
    }

    public function testWithoutDataTheCapabilitiesTableShowsAnEmptyState(): void
    {
        $body = (string) $this->controller()->shell(new ServerRequest('GET', '/desktop'))->getBody();

        self::assertStringContainsString('data-view="capabilities"', $body);
        self::assertStringContainsString('No capabilities reported', $body);
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
        self::assertStringContainsString('data-view="auth"', $body, 'the Auth screen');
        self::assertStringContainsString('data-pane="work"', $body);
        // Drag-drop wiring (0484): draggable cards, drop columns and the persisting POST.
        self::assertStringContainsString('class="work-board" data-session=', $body);
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
        // The client subscribes to the hub's PUBLIC url on the shell topic — no poll.
        self::assertStringContainsString('new EventSource(', $body);
        self::assertStringContainsString('https://public.example/.well-known/mercure?topic=desktop%2Fshell', $body);
        // The connection feeds the component runtime rather than dumping raw text.
        self::assertStringContainsString('MilpaShell.emit(', $body);
    }
}
