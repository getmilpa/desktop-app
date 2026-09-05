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

        // Discovery: the section, its group and who declared it — what the admin's catalogue KNOWS. The three
        // facts the decision expects on the PAGE («under the AGENT group», «declared by … DesktopAppPlugin», the
        // glyph) were a measured gap of milpa/admin 0.10.1 and are painted since 0.11.0 (greenhouse
        // decisions/0210): the page assertions below hold the host to them.
        $catalogue = SectionCatalogue::discover($kernel->plugins());
        $agent = $catalogue->find('agent');
        self::assertNotNull($agent, 'the admin discovered the Desktop\'s section');
        self::assertSame('agent', $agent->group, 'under the AGENT group');
        self::assertSame(60, $agent->order, 'after the host\'s own 10..40 (greenhouse decisions/0210)');
        self::assertSame(DesktopAppPlugin::class, $catalogue->declaredBy('agent'), 'declared by the Desktop plugin');

        // The sidebar (milpa/admin ≥ 0.11): the item sits under the AGENT group heading, with its glyph.
        $index = self::dispatch($kernel, '/milpa/admin');
        self::assertSame(200, $index->getStatusCode());
        $indexHtml = (string) $index->getBody();
        self::assertMatchesRegularExpression('~data-group="agent"[^>]*>\s*<span class="mui-sidebar__section-label"[^>]*>AGENT</span>~', $indexHtml, 'the AGENT group, painted by the host (its heading id is positional)');
        self::assertMatchesRegularExpression(
            '~<a class="mui-sidebar__item" href="/milpa/admin/s/agent"( aria-current="page")?><span class="mui-sidebar__item-icon" aria-hidden="true">◈</span><span class="mui-sidebar__item-label">Agent</span></a>~',
            $indexHtml,
        );
        // Order 60 sits after the host's own sections: the panel opens on Plugins, never on the guest.
        self::assertSame('plugins', $catalogue->first()?->id);
        self::assertStringContainsString('href="/milpa/admin/s/plugins" aria-current="page"', $indexHtml);

        // The section: the HOST puts the header; the guest emits the region — the live frame at the embed path.
        $section = self::dispatch($kernel, '/milpa/admin/s/agent');
        self::assertSame(200, $section->getStatusCode());
        $html = (string) $section->getBody();
        self::assertStringContainsString('<span class="mui-kbd">Agent</span>', $html, 'the host header names the section');
        self::assertStringContainsString('<title>Agent · Milpa Admin</title>', $html);
        self::assertStringContainsString('<div class="desktop-agent" id="milpa-admin-section-agent" data-desktop-agent="live" data-gate="loopback">', $html);
        // The host paints the attribution (milpa/admin ≥ 0.11): the section says who declared it.
        self::assertStringContainsString('data-declared-by="Milpa\DesktopApp\DesktopAppPlugin">declared by DesktopAppPlugin</span>', $html);
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
     * real admin. On milpa/admin 0.10.1 this was a measured CONTRACT GAP (greenhouse decisions/0210 §3: «if
     * something is missing there, it is a finding, not an assumption»): the admin's gate authenticated and its
     * topbar said who, but `AdminShell::render()` handed every section a `ComponentContext` without the
     * principal, so the region offered sign-in to a human already through the door. milpa/admin 0.11.0 fills
     * `principal:` in that one context; the region and the topbar now agree, and this test asserts exactly that.
     */
    public function testWithAPrincipalTheAdminSaysWhoSignedInAndTheRegionIsTheLiveFrame(): void
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

        // The host fills the context's principal: the region is the frame, as the decision names it.
        self::assertStringContainsString('data-desktop-agent="live" data-gate="passkey">', $html);
        self::assertStringContainsString('src="/desktop?embed=1"', $html);
        self::assertStringNotContainsString('Sign in to open the Agent', $html, 'the region agrees with the topbar');
        self::assertStringNotContainsString('data-desktop-agent="signed-out"', $html);
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
