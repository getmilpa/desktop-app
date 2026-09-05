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

use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Container\DIContainer;
use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\DesktopSettings;
use Milpa\DesktopApp\Tests\Fixtures\PasskeyGateStub;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The Desktop as the admin's guest, by execution (greenhouse decisions/0210): the REAL milpa/admin panel booted
 * by the real kernel next to the Desktop plugin — a plugin the admin never heard of, found by `instanceof` over
 * the booted plugins, as the admin's own suite proves with its HolaPlugin — and asked for its section.
 */
final class AdminGuestTest extends TestCase
{
    public function testTheAdminListsTheAgentSectionAndRendersTheDesktopInEmbedModeInsideIt(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, DesktopAppPlugin::class]);

        // Discovery: the section, its group and who declared it — what the admin's catalogue KNOWS. Three of
        // those facts the decision expects on the PAGE («under the AGENT group», «declared by … DesktopAppPlugin»,
        // the glyph) and milpa/admin 0.10.1 does not paint, its own sections included — a gap in the host,
        // reported, not patched here (greenhouse decisions/0210 §3): `AdminShell::navItems()` ignores
        // `AdminSection::$group` (one flat list, no group label), no section page prints the attribution
        // `SectionCatalogue::declaredBy()` holds (only the Stack section attributes services), and the sidebar
        // item's icon span is empty for every section, measured with an ASCII glyph too. So the page assertions
        // below stop at what the host renders, and the catalogue assertions stand for the rest.
        $catalogue = SectionCatalogue::discover($kernel->plugins());
        $agent = $catalogue->find('agent');
        self::assertNotNull($agent, 'the admin discovered the Desktop\'s section');
        self::assertSame('agent', $agent->group, 'under the AGENT group — data the host holds and does not yet paint');
        self::assertSame(10, $agent->order);
        self::assertSame(DesktopAppPlugin::class, $catalogue->declaredBy('agent'), 'declared by the Desktop plugin — attribution the host holds and does not yet paint');

        // The sidebar lists it by title and route.
        $index = self::dispatch($kernel, '/milpa/admin');
        self::assertSame(200, $index->getStatusCode());
        self::assertMatchesRegularExpression(
            '~<a class="mui-sidebar__item" href="/milpa/admin/s/agent"( aria-current="page")?><span class="mui-sidebar__item-icon" aria-hidden="true">[^<]*</span><span class="mui-sidebar__item-label">Agent</span></a>~',
            (string) $index->getBody(),
        );
        // Order 10 ties with the admin's own Plugins section and the id breaks the tie: the panel OPENS on the Agent.
        self::assertSame('agent', $catalogue->first()?->id);
        self::assertStringContainsString('href="/milpa/admin/s/agent" aria-current="page"', (string) $index->getBody());

        // The section: the HOST puts the header; the guest emits the region — the live frame at the embed path.
        $section = self::dispatch($kernel, '/milpa/admin/s/agent');
        self::assertSame(200, $section->getStatusCode());
        $html = (string) $section->getBody();
        self::assertStringContainsString('<span class="mui-kbd">Agent</span>', $html, 'the host header names the section');
        self::assertStringContainsString('<title>Agent · Milpa Admin</title>', $html);
        self::assertStringContainsString('<div class="desktop-agent" id="milpa-admin-section-agent" data-desktop-agent="live" data-gate="loopback">', $html);
        self::assertStringContainsString('src="/desktop?embed=1" title="Milpa Desktop — Agent"', $html);
        self::assertStringNotContainsString('loading="lazy"', $html);
        self::assertStringContainsString('data-gate="loopback">gate: loopback</span>', $html, 'the guest bar says the Desktop\'s gate');
        self::assertStringContainsString('href="/desktop" target="_blank" rel="noopener">Open the Desktop</a>', $html);
        self::assertStringContainsString('id="milpa-admin-section-agent-notice" role="alert" hidden>The Agent did not answer</p>', $html, 'the contained error notice, hidden until onerror');

        // The frame's target answers, in embed mode, through the same kernel and the same door.
        $frame = self::dispatch($kernel, '/desktop?embed=1');
        self::assertSame(200, $frame->getStatusCode());
        self::assertStringContainsString('<html data-theme="dark" lang="en" data-embed="1">', (string) $frame->getBody());

        // The request's language reaches the region through the ComponentContext the admin hands it.
        $spanish = (string) self::dispatch($kernel, '/milpa/admin/s/agent?lang=es')->getBody();
        self::assertStringContainsString('>Abrir el Desktop</a>', $spanish);
        self::assertStringContainsString('title="Milpa Desktop — Agente"', $spanish);
    }

    public function testTheDeclaredLocaleNamesTheSectionInSpanish(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, DesktopAppPlugin::class], ['desktop' => ['locale' => 'es'], 'admin' => ['locale' => 'es']]);

        $index = (string) self::dispatch($kernel, '/milpa/admin')->getBody();
        self::assertStringContainsString('<span class="mui-sidebar__item-label">Agente</span>', $index);
        $section = (string) self::dispatch($kernel, '/milpa/admin/s/agent')->getBody();
        self::assertStringContainsString('<span class="mui-kbd">Agente</span>', $section);
        self::assertStringContainsString('>puerta: loopback</span>', $section);
    }

    public function testWithoutTheDesktopPluginTheAdminHasNoAgentSection(): void
    {
        // The negative control: the section comes from the Desktop plugin and nowhere else.
        [, $kernel] = self::boot([AdminPlugin::class]);

        self::assertNull(SectionCatalogue::discover($kernel->plugins())->find('agent'));
        $missing = self::dispatch($kernel, '/milpa/admin/s/agent');
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringNotContainsString('href="/milpa/admin/s/agent"', (string) self::dispatch($kernel, '/milpa/admin')->getBody());
    }

    public function testBehindThePasskeyGateWithNobodySignedInTheSectionOffersTheDoorNotTheFrame(): void
    {
        // The Desktop names app-runtime's passkey gate (the fixture bears its exact name); the admin's own door
        // is the default loopback gate, which authenticates nobody: the region must offer sign-in, not a frame
        // that would bounce to sign-in inside the region.
        if (!class_exists(DesktopSettings::PASSKEY_GATE)) {
            require __DIR__ . '/../Fixtures/app-runtime-passkey-gate.php';
        }
        [, $kernel] = self::boot([AdminPlugin::class, DesktopAppPlugin::class], ['desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]]]);

        $section = self::dispatch($kernel, '/milpa/admin/s/agent');
        self::assertSame(200, $section->getStatusCode());
        $html = (string) $section->getBody();
        self::assertStringContainsString('<span class="mui-kbd">Agent</span>', $html);
        self::assertStringContainsString('data-desktop-agent="signed-out" data-gate="passkey">', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringContainsString('Sign in to open the Agent', $html);
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fmilpa%2Fadmin%2Fs%2Fagent">Sign in</a>', $html, 'next points back at this section');

        // A Spanish page comes back to a Spanish page: the request's `lang` reaches the guest as the host's
        // `query` prop and travels in next.
        $spanish = (string) self::dispatch($kernel, '/milpa/admin/s/agent?lang=es')->getBody();
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fmilpa%2Fadmin%2Fs%2Fagent%3Flang%3Des">Iniciar sesión</a>', $spanish);

        // The admin mounted elsewhere: the way back follows its mount point, read from the context's route.
        [, $panel] = self::boot([AdminPlugin::class, DesktopAppPlugin::class], ['desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]], 'admin' => ['route' => '/panel']]);
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fpanel%2Fs%2Fagent">', (string) self::dispatch($panel, '/panel/s/agent')->getBody());
    }

    /**
     * The state the decision names for a passkey gate AND a principal — the live frame — measured through the
     * real admin, and the CONTRACT GAP that keeps it from happening on milpa/admin 0.10.1 (greenhouse
     * decisions/0210 §3: «if something is missing there, it is a finding, not an assumption»): the admin's gate
     * authenticates (its topbar says who), but `AdminShell::render()` builds the `ComponentContext` it hands
     * every section with `componentId`, `locale` and `route` only — the `$principal` it received never enters
     * the context — so the guest is told nobody is signed in while the topbar says who is, and behind a
     * passkey-gated Desktop the region offers sign-in to everyone, forever. The fix is one line in the admin
     * (`principal: $principal` at that constructor), not in this package: the component and the renderer already
     * honor a principal in the context (AgentGuestComponentTest, AgentGuestRendererTest), and this test asserts
     * the decision's state the day the host fills it — until then it asserts the gap, with its control.
     */
    public function testWithAPrincipalTheAdminSaysWhoSignedInButHandsItsSectionsNoPrincipalTheMeasuredHostGap(): void
    {
        if (!class_exists(DesktopSettings::PASSKEY_GATE)) {
            require __DIR__ . '/../Fixtures/app-runtime-passkey-gate.php';
        }
        $container = new DIContainer();
        $container->registerService(PasskeyGateStub::class, new PasskeyGateStub());
        [, $kernel] = self::boot(
            [AdminPlugin::class, DesktopAppPlugin::class],
            ['admin' => ['middleware' => [PasskeyGateStub::class]], 'desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]]],
            $container,
        );

        $section = self::dispatch($kernel, '/milpa/admin/s/agent', '203.0.113.9');
        self::assertSame(200, $section->getStatusCode(), 'the admin\'s gate let the LAN in by identity');
        $html = (string) $section->getBody();
        self::assertStringContainsString('data-principal="passkey:stub">signed in as passkey:stub</span>', $html, 'the admin knows who signed in');

        if (str_contains($html, 'data-desktop-agent="live"')) {
            // The host fills the context's principal: the region is the frame, as the decision names it.
            self::assertStringContainsString('data-desktop-agent="live" data-gate="passkey">', $html);
            self::assertStringContainsString('src="/desktop?embed=1"', $html);
            self::assertStringNotContainsString('Sign in to open the Agent', $html);

            return;
        }

        // The gap, measured on milpa/admin 0.10.1 with its control on the same page: the topbar names the
        // principal (the admin authenticated the request), the region is told nobody did (the context carried
        // no principal). Reported upstream; nothing in this package can close it.
        self::assertStringContainsString('data-desktop-agent="signed-out" data-gate="passkey">', $html, 'milpa/admin 0.10.1 hands its sections a ComponentContext without the principal its topbar shows');
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fmilpa%2Fadmin%2Fs%2Fagent">', $html, 'so the region offers the door to a human who is already through it');
        self::assertStringNotContainsString('<iframe', $html);
    }

    /**
     * @param list<class-string>   $plugins
     * @param array<string, mixed> $config
     *
     * @return array{0: DIContainer, 1: Kernel}
     */
    private static function boot(array $plugins, array $config = [], ?DIContainer $container = null): array
    {
        $container ??= new DIContainer();
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => $plugins, 'config' => $config, 'container' => $container]);
        // The admin reads the booted plugins from the kernel in the container, as an app's public/index.php registers it.
        $container->registerService(Kernel::class, $kernel);

        return [$container, $kernel];
    }

    private static function dispatch(Kernel $kernel, string $path, string $address = '127.0.0.1'): ResponseInterface
    {
        $request = new ServerRequest('GET', $path, ['Accept' => 'text/html'], null, '1.1', ['REMOTE_ADDR' => $address]);

        return (new RequestHandler($kernel, new Psr17Factory()))->handle($request);
    }
}
