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

use Milpa\DesktopApp\Controllers\ShellController;
use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\Eventing\EventDispatcher;
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
