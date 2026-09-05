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
use Milpa\DesktopApp\I18n\Catalog;
use Milpa\DesktopApp\Live\SessionStrip;
use Milpa\Eventing\EventDispatcher;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Embed mode (greenhouse decisions/0210): `GET /desktop?embed=1` serves the SAME page with the chrome folded by
 * CSS and the DOM kept — every id the shell script looks up at boot stays — plus a session strip that keeps the
 * sessions reachable, and no link that would open a chrome screen inside the host.
 */
final class EmbedModeTest extends TestCase
{
    private const CHROME_NAV = ['settings', 'decisions', 'capabilities', 'skills', 'preview'];

    /** The root element as served plain, and with the flag. The CSS that folds the chrome names `data-embed` on every page: the ROOT is what tells the modes apart. */
    private const ROOT_PLAIN = '<html data-theme="dark" lang="en">';
    private const ROOT_EMBED = '<html data-theme="dark" lang="en" data-embed="1">';

    private function shell(?DesktopData $data = null): ShellController
    {
        return new ShellController(new EventDispatcher(new NullLogger()), null, $data);
    }

    public function testTheFlagMarksTheRootAndFoldsTheChromeByCssOnly(): void
    {
        $plain = (string) $this->shell()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $embed = (string) $this->shell()->shell((new ServerRequest('GET', '/desktop?embed=1'))->withQueryParams(['embed' => '1']))->getBody();

        self::assertStringContainsString(self::ROOT_PLAIN, $plain);
        self::assertStringNotContainsString(self::ROOT_EMBED, $plain, 'the flag is not on a plain page');
        self::assertStringContainsString(self::ROOT_EMBED, $embed, 'the flag on the root element');
        self::assertStringNotContainsString(self::ROOT_PLAIN, $embed);
        // The chrome folds by CSS — the rules are in the page, keyed on the root's flag — and the DOM stays.
        self::assertStringContainsString('html[data-embed="1"] .chrome, html[data-embed="1"] .statusbar, html[data-embed="1"] .mui-sidebar, html[data-embed="1"] .mui-topbar { display: none !important; }', $embed);
        self::assertStringContainsString('html[data-embed="1"] .mui-shell__main { grid-column: 1 !important; grid-row: 1 !important; }', $embed);
        self::assertStringContainsString('<div class="chrome">', $embed);
        self::assertStringContainsString('<div class="statusbar">', $embed);
        self::assertStringContainsString('data-milpa-component="desktop-sidebar"', $embed);
        self::assertStringContainsString('data-milpa-component="desktop-topbar"', $embed);
        // The same route, the same page: nothing else changes but the flag, the strip and the chrome links.
        self::assertStringContainsString('Milpa Desktop', $embed);
        self::assertStringContainsString('id="milpa-chat"', $embed);
    }

    public function testEveryIdTheShellScriptLooksUpStaysInTheEmbedDom(): void
    {
        $plain = (string) $this->shell()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $embed = (string) $this->shell()->shell((new ServerRequest('GET', '/desktop'))->withQueryParams(['embed' => '1']))->getBody();

        preg_match_all("/getElementById\\('([^']+)'\\)/", $plain, $m);
        $lookedUp = array_values(array_unique($m[1]));
        self::assertGreaterThanOrEqual(30, \count($lookedUp), 'the script contract: the ids the shell looks up at boot');
        // The contract is the ids the plain page HAS (a few are created live, e.g. the decisions list): each one
        // of those must still be in the embed document, or the script would break at boot.
        $present = array_values(array_filter($lookedUp, static fn (string $id): bool => str_contains($plain, 'id="' . $id . '"')));
        self::assertGreaterThanOrEqual(30, \count($present));
        foreach ($present as $id) {
            self::assertStringContainsString('id="' . $id . '"', $embed, 'id «' . $id . '» must stay in the embed DOM');
        }
        // Nothing the script needs is removed: the ids it looks up are the same in both renders.
        preg_match_all("/getElementById\\('([^']+)'\\)/", $embed, $e);
        self::assertSame($lookedUp, array_values(array_unique($e[1])));
    }

    public function testTheSessionStripIsRenderedOnlyInEmbedModeAndWiredToTheSameHandlers(): void
    {
        $plain = (string) $this->shell()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $embed = (string) $this->shell()->shell((new ServerRequest('GET', '/desktop'))->withQueryParams(['embed' => '1']))->getBody();

        self::assertStringNotContainsString('id="milpa-session-strip"', $plain);
        self::assertStringNotContainsString('data-new-session>New session</button>', $plain, 'no strip control on a plain page (the script that would wire one is always there)');
        self::assertStringNotContainsString('data-milpa-component="desktop-session-strip"', $plain);
        // A Milpa Component with its signed envelope, like every shell surface (greenhouse decisions/0189).
        self::assertStringContainsString('<div class="milpa-session-strip" id="milpa-session-strip" role="region" aria-label="Session" data-milpa-runtime="alpine" data-milpa-component="desktop-session-strip" data-milpa-component-id="session-strip" x-data>', $embed);
        self::assertStringContainsString('data-milpa-state="session-strip"', $embed);
        self::assertStringContainsString('id="milpa-embed-session"', $embed);
        self::assertStringContainsString('<button type="button" class="mui-btn mui-btn--subtle mui-btn--sm" data-new-session>New session</button>', $embed);
        self::assertStringContainsString('No session open', $embed);
        // Above the conversation: the strip precedes the tablist inside the session view.
        self::assertLessThan(strpos($embed, 'data-milpa-component="desktop-tabs"'), strpos($embed, 'id="milpa-session-strip"'));
        // The SAME handler as the sidebar's button; picking a session navigates keeping embed=1.
        self::assertStringContainsString("if (newBtn) { newBtn.addEventListener('click', openNewSession); }", $embed);
        self::assertStringContainsString("document.querySelectorAll('[data-new-session]').forEach(function (b) { b.addEventListener('click', openNewSession); });", $embed);
        self::assertStringContainsString("location.assign('?session=' + encodeURIComponent(embedPick.value) + '&embed=1')", $embed);
        // The guard's next carries the path AND the query, so a sign-in round trip lands back in embed mode.
        self::assertStringContainsString("encodeURIComponent(location.pathname + location.search)", $embed);
    }

    public function testTheStripListsTheSessionsWithTheCurrentOneSelected(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-embed-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/aaa11111.json', json_encode(['goal' => 'First goal', 'state' => 'ready'], JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/bbb22222.json', json_encode(['goal' => 'Second goal', 'state' => 'working'], JSON_THROW_ON_ERROR));
        $data = new DesktopData(new DIContainer(), null, $dir);

        $embed = (string) $this->shell($data)->shell((new ServerRequest('GET', '/desktop'))->withQueryParams(['embed' => '1', 'session' => 'aaa11111']))->getBody();

        self::assertStringContainsString('<span class="milpa-session-strip__goal" id="milpa-session-strip-goal">First goal</span>', $embed);
        self::assertStringContainsString('<option value="aaa11111" selected>First goal · ready</option>', $embed);
        self::assertStringContainsString('<option value="bbb22222">Second goal · working</option>', $embed);
        self::assertStringNotContainsString('>No session open</span>', $embed, 'the goal line names the session (the catalog JSON always carries the phrase)');

        unlink($dir . '/aaa11111.json');
        unlink($dir . '/bbb22222.json');
        rmdir($dir);
    }

    public function testTheStripSpeaksTheShellsCatalogAndAnInjectedStripIsTheOneRendered(): void
    {
        // The fallback strip speaks the shell's catalog (the declared locale); an injected one is rendered as is.
        $es = (string) (new ShellController(new EventDispatcher(new NullLogger()), null, null, catalog: new Catalog('es')))
            ->shell((new ServerRequest('GET', '/desktop'))->withQueryParams(['embed' => '1']))->getBody();
        self::assertStringContainsString('aria-label="Sesión" data-milpa-runtime="alpine"', $es);
        self::assertStringContainsString('data-new-session>Nueva sesión</button>', $es);

        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(SessionStrip::AFTER_RENDER, static function (string $n, array $p): void {
            $p['sessionStrip']->html .= '<!-- injected strip -->';
        });
        $injected = (string) (new ShellController(new EventDispatcher(new NullLogger()), sessionStrip: new SessionStrip('secret', null, $events)))
            ->shell((new ServerRequest('GET', '/desktop'))->withQueryParams(['embed' => '1']))->getBody();
        self::assertStringContainsString('<!-- injected strip -->', $injected);
    }

    public function testLinksToChromeScreensAreNotRenderedInEmbed(): void
    {
        $plain = (string) $this->shell()->shell(new ServerRequest('GET', '/desktop'))->getBody();
        $embed = (string) $this->shell()->shell((new ServerRequest('GET', '/desktop'))->withQueryParams(['embed' => '1']))->getBody();

        // The LINKS — the sidebar anchors; the shell script always names the nav keys it would react to.
        foreach (self::CHROME_NAV as $nav) {
            self::assertStringContainsString('<a class="mui-sidebar__item" href="#" data-nav="' . $nav . '"', $plain, 'the plain shell links to ' . $nav);
            self::assertStringNotContainsString('href="#" data-nav="' . $nav . '"', $embed, 'embed does not link to ' . $nav);
        }
        self::assertStringContainsString('<a class="mui-sidebar__item" href="#" data-nav="sessions"', $embed, 'the sessions item is not a chrome screen');
        // The screens themselves stay in the DOM (their ids are part of the script contract); only the links go.
        self::assertStringContainsString('data-view="settings"', $embed);
        self::assertStringContainsString('id="milpa-new-session"', $embed);
        self::assertStringContainsString('id="milpa-enroll-link"', $embed);
    }

    public function testTheFlagIsReadFromTheUriWhenTheRuntimeDidNotParseTheQueryAndOnlyOneCounts(): void
    {
        // A bare PSR-7 request carries the query in the URI only — the way app-runtime's sign-in page reads `next`.
        $fromUri = (string) $this->shell()->shell(new ServerRequest('GET', '/desktop?embed=1'))->getBody();
        self::assertStringContainsString(self::ROOT_EMBED, $fromUri);
        self::assertStringContainsString('id="milpa-session-strip"', $fromUri);

        foreach (['0', 'true', 'yes', ''] as $value) {
            $body = (string) $this->shell()->shell((new ServerRequest('GET', '/desktop'))->withQueryParams(['embed' => $value]))->getBody();
            self::assertStringContainsString(self::ROOT_PLAIN, $body, 'embed=' . $value . ' is not embed mode');
            self::assertStringNotContainsString('id="milpa-session-strip"', $body);
        }
        // Per KEY, as app-runtime's sign-in page reads `next`: a parsed bag that lacks the key does not hide the
        // URI's; a parsed bag that carries it is the host's answer, whatever the URI says.
        $uriFills = (string) $this->shell()->shell((new ServerRequest('GET', '/desktop?embed=1'))->withQueryParams(['session' => 'x']))->getBody();
        self::assertStringContainsString(self::ROOT_EMBED, $uriFills, 'the parsed params lack the key: the URI fills it');
        $parsedWins = (string) $this->shell()->shell((new ServerRequest('GET', '/desktop?embed=1'))->withQueryParams(['embed' => '0']))->getBody();
        self::assertStringContainsString(self::ROOT_PLAIN, $parsedWins, 'the parsed params carry the key: they are the host\'s answer');
    }
}
