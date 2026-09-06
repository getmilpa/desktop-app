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
use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\Live\Sidebar;
use Milpa\DesktopApp\Live\SidebarComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The sidebar is the shell's first pure-Milpa-Components surface (greenhouse decisions/0189): a declared
 * component with a signed envelope, lifecycle events and a signal-driven active nav.
 */
final class SidebarTest extends TestCase
{
    public function testItRendersAsAMilpaLiveComponentWithASignalDrivenNav(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-sidebar-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/aaa11111.json', json_encode(['goal' => 'First goal', 'state' => 'ready'], JSON_THROW_ON_ERROR));
        $data = new DesktopData(new DIContainer(), null, $dir);

        $html = (new Sidebar('secret', $data))->render();

        // A real component: the root declares it, and the signed state envelope rides along.
        self::assertStringContainsString('data-milpa-component="desktop-sidebar"', $html);
        self::assertStringContainsString('data-milpa-state="sidebar"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // The active nav is the shared `desktop.nav` signal: click sets it, aria-current tracks it.
        // A DECLARED VIEW (greenhouse decisions/0211): the markup asks the `desktopSidebar` factory —
        // `go()` sets the signal AND swaps the view, `isCurrent()` is what aria-current binds to.
        self::assertStringContainsString('x-data="desktopSidebar({ active: \'sessions\' })"', $html);
        self::assertStringContainsString('@click.prevent="go(\'settings\')"', $html);
        self::assertStringContainsString(':aria-current="isCurrent(\'sessions\') ? \'page\' : null"', $html);
        self::assertStringNotContainsString('$store.milpa', $html, 'the store is the factory\'s to touch, not the markup\'s');
        // «New session» and the passkey probe are the component's own verbs now, not the page's listeners.
        self::assertStringContainsString('@click="newSession()"', $html);
        self::assertStringContainsString('@click.prevent="enroll($event)"', $html);
        // Its look is a declared file: only the per-kernel animation delay stays inline (it is data).
        self::assertStringContainsString('class="mui-sidebar milpa-sidebar"', $html);
        self::assertSame(13, substr_count($html, 'style="animation-delay:'), 'the only inline styles left are the mark\'s stagger');
        self::assertSame(13, substr_count($html, 'style='));
        // Brand (the Grano mark), the session from the store, and the actions are all in the component.
        self::assertSame(13, substr_count($html, 'class="g"'));
        self::assertStringContainsString('href="?session=aaa11111"', $html);
        self::assertStringContainsString('id="milpa-new-session"', $html);

        unlink($dir . '/aaa11111.json');
        rmdir($dir);
    }

    public function testEmptySessionsShowTheEmptyState(): void
    {
        $html = (new Sidebar('secret'))->render();

        self::assertStringContainsString('No sessions yet', $html);
    }

    public function testWithoutChromeItKeepsItsIdsButLinksToNoChromeScreen(): void
    {
        // Embed mode (greenhouse decisions/0210): the host's navigation stands, so the links that would open a
        // chrome screen are not rendered; the session list and the actions keep their ids for the shell script.
        $html = (new Sidebar('secret'))->render(false);

        foreach (['settings', 'decisions', 'capabilities', 'skills', 'preview'] as $nav) {
            self::assertStringNotContainsString('data-nav="' . $nav . '"', $html);
        }
        self::assertStringContainsString('data-nav="sessions"', $html);
        self::assertStringContainsString('id="milpa-sessions"', $html);
        self::assertStringContainsString('id="milpa-new-session"', $html);
        self::assertStringContainsString('id="milpa-enroll-link"', $html);
        self::assertStringContainsString('data-milpa-state="sidebar"', $html, 'still a component with its envelope');

        // The flag is part of the component's contract and lands in its state.
        self::assertSame('bool', SidebarComponent::contract()->propsSchema['chrome']['type']);
        $state = (new SidebarComponent())->mount(['chrome' => false], new ComponentContext('sidebar'));
        self::assertFalse($state->meta['chrome']);
        self::assertTrue((new SidebarComponent())->mount([], new ComponentContext('sidebar'))->meta['chrome'], 'the chrome is there by default');
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(Sidebar::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['sidebar']->props['activeNav'] = 'settings';
        });
        $events->subscribe(Sidebar::AFTER_RENDER, static function (string $n, array $p): void {
            $p['sidebar']->html .= '<!-- sidebar extended -->';
        });

        $html = (new Sidebar('secret', null, $events))->render();

        self::assertStringContainsString('sidebar extended', $html, 'after_render changed the html');
        // before_render changed the active nav, so the server-rendered aria-current lands on Settings.
        self::assertStringContainsString('data-nav="settings" aria-current="page"', $html);
    }

    public function testTheComponentSelectActionDeclaresTheNavSignal(): void
    {
        $contract = SidebarComponent::contract();
        self::assertSame('desktop-sidebar', $contract->name);
        self::assertArrayHasKey('select', $contract->actions);

        $component = new SidebarComponent();
        $state = $component->mount(['activeNav' => 'sessions'], new ComponentContext('sidebar'));
        $result = $component->handle(new InteractionRequest('sidebar', 'desktop-sidebar', 'select', $state, ['nav' => 'settings']));

        self::assertSame('settings', $result->state->data['activeNav']);
        self::assertSame([['type' => 'state', 'key' => 'desktop.nav', 'value' => 'settings']], $result->effects);
    }
}
