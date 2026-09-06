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

namespace Milpa\DesktopApp\Tests\Admin;

use Milpa\DesktopApp\Admin\AgentGuestComponent;
use Milpa\DesktopApp\Admin\AgentGuestRenderer;
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\SidebarComponent;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The Agent section's renderer (greenhouse decisions/0210): the REGION only, in three states — the live frame with
 * its guest bar, the sign-in offer with `next` pointing back at the section, and the contained error hook.
 */
final class AgentGuestRendererTest extends TestCase
{
    private const PROPS = ['embed' => '/desktop?embed=1', 'open' => '/desktop', 'gate' => 'loopback', 'signin' => '/webauthn/signin'];

    public function testLiveIsASameOriginFrameFillingTheRegionPlusAGuestBar(): void
    {
        $renderer = new AgentGuestRenderer();
        self::assertInstanceOf(ComponentRendererInterface::class, $renderer);

        $result = $renderer->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('milpa-admin-section-agent', route: '/milpa/admin'), self::PROPS));
        $html = $result->output;

        self::assertNotNull($result->state, 'the renderer mounted, so it hands the snapshot back');
        self::assertSame('live', $result->state->data['state']);
        self::assertStringStartsWith('<div class="desktop-agent" id="milpa-admin-section-agent" data-desktop-agent="live" data-gate="loopback">', $html);
        // The frame: same origin, the embed path, a title, never lazy — the Agent is the point of the page.
        self::assertStringContainsString('<iframe class="desktop-agent__frame" id="milpa-admin-section-agent-frame" src="/desktop?embed=1" title="Milpa Desktop — Agent"', $html);
        self::assertStringNotContainsString('loading="lazy"', $html);
        // The look and the probe are DECLARED FILES, served by the Desktop's own gate-free asset route
        // (greenhouse decisions/0211 review): this region used to carry an inline <style> and two inline
        // event handlers — the only executable inline script the package emitted after phase D.
        self::assertStringContainsString('<link rel="stylesheet" href="/desktop/assets/c/desktop-agent-guest.css">', $html);
        self::assertStringContainsString('<script src="/desktop/assets/c/desktop-agent-guest.js" defer></script>', $html);
        self::assertStringNotContainsString('<style>', $html, 'the region declares its look, it does not inline it');
        // …and the height it declares is still the main's available height, from the host's own tokens.
        $css = (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/components/desktop-agent-guest/desktop-agent-guest.css');
        self::assertStringContainsString('height: calc(100dvh - var(--_topbar-h, var(--header-h, 3.5rem)) - 2*clamp(var(--space-5, 1.25rem), 3vw, var(--space-8, 2rem)));', $css);
        self::assertStringContainsString('.desktop-agent__frame { flex: 1; min-height: 0; width: 100%;', $css);
        // The guest bar: the gate chip the topbar also says, and the way to the full Desktop in a new tab.
        self::assertStringContainsString('<span class="mui-badge desktop-chip desktop-chip--gate" data-gate="loopback">gate: loopback</span>', $html);
        self::assertStringContainsString('<a class="mui-btn mui-btn--sm desktop-agent__open" href="/desktop" target="_blank" rel="noopener">Open the Desktop</a>', $html);
        // The contained error, inside the region only and with no admin JS: browsers report a failed frame
        // navigation as a `load` of their own (cross-origin) error page, so the reachable hook is a `load`
        // with no same-origin document; `error` stays for the engines that fire it. Both reveal the same
        // notice. Measured in Chrome 152 with the inline form of this probe: a frame at a closed port →
        // `contentDocument` null → frame hidden, notice shown; a same-origin 403 → the 403 stays inside the
        // frame, notice hidden (the decision's F6); a same-origin embed page → untouched.
        self::assertStringNotContainsString('onload=', $html, 'no inline handler: the probe is a declared module');
        self::assertStringNotContainsString('onerror=', $html);
        $probe = (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/components/desktop-agent-guest/desktop-agent-guest.js');
        self::assertStringContainsString('return !!frame.contentDocument;', $probe, 'the same test, in the file that runs it');
        self::assertStringContainsString("frame.addEventListener('load', function () { report(root); });", $probe);
        self::assertStringContainsString("frame.addEventListener('error', function () { report(root); });", $probe);
        self::assertStringContainsString('notice.hidden = false;', $probe);
        // The frame keeps its `src` in the MARKUP, so the Agent is there with no JavaScript at all — only
        // the «did not answer» report needs the module.
        self::assertStringContainsString('src="/desktop?embed=1" title="Milpa Desktop — Agent"></iframe>', $html);
        self::assertStringContainsString('<p class="mui-alert mui-alert--warning desktop-agent__notice" id="milpa-admin-section-agent-notice" role="alert" hidden>The Agent did not answer</p>', $html);
        self::assertStringNotContainsString('Sign in', $html);
    }

    public function testSignedOutOffersTheDoorWithNextPointingBackAtTheSectionAndMountsNoFrame(): void
    {
        $context = new ComponentContext('milpa-admin-section-agent', principal: null, route: '/milpa/admin');
        $html = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest($context, ['gate' => 'passkey'] + self::PROPS))->output;

        self::assertStringStartsWith('<div class="desktop-agent" id="milpa-admin-section-agent" data-desktop-agent="signed-out" data-gate="passkey">', $html);
        self::assertStringNotContainsString('<iframe', $html, 'no frame: it would only bounce to sign-in inside the region');
        self::assertStringContainsString('Sign in to open the Agent', $html);
        self::assertStringContainsString('<a class="mui-btn mui-btn--primary mui-btn--sm desktop-agent__signin-link" href="/webauthn/signin?next=%2Fmilpa%2Fadmin%2Fs%2Fagent">Sign in</a>', $html);
        self::assertStringNotContainsString('Open the Desktop', $html);
        self::assertStringNotContainsString('The Agent did not answer', $html);

        // The admin mounted elsewhere: next follows the route the context carries; no route: the admin's default.
        $panel = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c', route: '/panel'), ['gate' => 'passkey'] + self::PROPS))->output;
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fpanel%2Fs%2Fagent"', $panel);
        $bare = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c'), ['gate' => 'passkey'] + self::PROPS))->output;
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fmilpa%2Fadmin%2Fs%2Fagent"', $bare);
        // The language the request carried (the host's `query` prop) travels in next, so the way back keeps it.
        $lang = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c', locale: 'es', route: '/milpa/admin'), ['gate' => 'passkey', 'query' => ['lang' => 'es']] + self::PROPS))->output;
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fmilpa%2Fadmin%2Fs%2Fagent%3Flang%3Des">Iniciar sesión</a>', $lang);

        // The control: the same gate with a principal in the context is the live frame, not the door.
        $signedIn = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c', principal: 'passkey:rod'), ['gate' => 'passkey'] + self::PROPS))->output;
        self::assertStringContainsString('data-desktop-agent="live" data-gate="passkey">', $signedIn);
        self::assertStringContainsString('src="/desktop?embed=1"', $signedIn);
        self::assertStringContainsString('data-gate="passkey">gate: passkey</span>', $signedIn);
    }

    public function testEveryWordComesFromTheDesktopsCatalogInTheRequestsLocale(): void
    {
        // The context's locale wins when the Desktop carries it: an `?lang=es` admin page renders the region in Spanish.
        $es = (new AgentGuestRenderer(new Catalog('en')))->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c', locale: 'es'), self::PROPS))->output;
        self::assertStringContainsString('>Abrir el Desktop</a>', $es);
        self::assertStringContainsString('title="Milpa Desktop — Agente"', $es);
        self::assertStringContainsString('>El Agente no respondió</p>', $es);
        self::assertStringContainsString('>puerta: loopback</span>', $es);
        $esOut = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c', locale: 'es'), ['gate' => 'passkey'] + self::PROPS))->output;
        self::assertStringContainsString('Inicia sesión para abrir el Agente', $esOut);
        self::assertStringContainsString('>Iniciar sesión</a>', $esOut);

        // A locale the catalog lacks falls to the catalog the Desktop declared; no catalog at all is English.
        $declared = (new AgentGuestRenderer(new Catalog('es')))->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c', locale: 'fr'), self::PROPS))->output;
        self::assertStringContainsString('>Abrir el Desktop</a>', $declared);
        $english = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c', locale: 'fr'), self::PROPS))->output;
        self::assertStringContainsString('>Open the Desktop</a>', $english);
    }

    public function testAGivenStateIsRenderedAsIsWithoutRemounting(): void
    {
        // Re-rendering from a snapshot (the interface's contract): the state decides, the props are not consulted.
        $state = new StateSnapshot('c', 'desktop-agent', '1', ['state' => 'signed-out'], ['gate' => 'passkey', 'signin' => '/door', 'next' => '/x/s/agent', 'locale' => 'es']);
        $result = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c'), ['gate' => 'loopback'], $state));

        self::assertSame($state, $result->state);
        self::assertStringContainsString('data-desktop-agent="signed-out"', $result->output);
        self::assertStringContainsString('href="/door?next=%2Fx%2Fs%2Fagent"', $result->output);
        self::assertStringContainsString('Inicia sesión', $result->output, 'the locale the snapshot remembers, when the context carries none');

        // A snapshot with empty meta still renders: every path falls to its default.
        $bare = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c'), [], new StateSnapshot('c', 'desktop-agent', '1', ['state' => 'live'])));
        self::assertStringContainsString('src="/desktop?embed=1"', $bare->output);
        self::assertStringContainsString('href="/desktop" target="_blank"', $bare->output);
    }

    public function testItRendersHtmlOnlyAndOnlyItsOwnComponent(): void
    {
        $renderer = new AgentGuestRenderer();

        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));
        self::assertFalse($renderer->supportsTarget(RenderTarget::ANSI));

        try {
            $renderer->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c'), self::PROPS, null, RenderTarget::TUI));
            self::fail('a TUI request must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('renders HTML only, not «tui»', $e->getMessage());
        }

        try {
            $renderer->render(new SidebarComponent(), new RenderRequest(new ComponentContext('c'), []));
            self::fail('a foreign component must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('renders only «desktop-agent», not «desktop-sidebar»', $e->getMessage());
        }
    }

    public function testValuesAreEscapedIntoAttributes(): void
    {
        $html = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext('c"><x'), ['embed' => '/desktop?embed=1&"', 'open' => '/desktop"'] + self::PROPS))->output;

        self::assertStringContainsString('id="c&quot;&gt;&lt;x"', $html);
        self::assertStringContainsString('src="/desktop?embed=1&amp;&quot;"', $html);
        self::assertStringContainsString('href="/desktop&quot;"', $html);
        self::assertStringNotContainsString('"><x', $html);

        // The notice's id no longer travels through a JS string literal — the probe finds the notice by the
        // component's own class INSIDE the region, so a quote in the id can only ever be attribute data.
        $quoted = (new AgentGuestRenderer())->render(new AgentGuestComponent(), new RenderRequest(new ComponentContext("it's\\me"), self::PROPS))->output;
        self::assertStringContainsString('id="it&#039;s\\me-notice"', $quoted);
        self::assertStringNotContainsString('getElementById', $quoted, 'nothing in the markup names the notice to a script');
    }
}
