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
        self::assertStringContainsString("\$store.milpa['desktop.nav'] = 'settings'", $html);
        self::assertStringContainsString("\$store.milpa['desktop.nav'] === 'sessions' ? 'page' : null", $html);
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
