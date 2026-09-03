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

use Milpa\DesktopApp\Live\Tabs;
use Milpa\DesktopApp\Live\TabsComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The tablist is the shell's third pure-Milpa-Components surface (greenhouse decisions/0189): a declared
 * component whose `select` action declares the shared `desktop.tab` signal that the panes and dock read.
 */
final class TabsTest extends TestCase
{
    public function testItRendersAsAMilpaLiveComponentWithASignalDrivenActiveTab(): void
    {
        $html = (new Tabs('secret'))->render();

        self::assertStringContainsString('data-milpa-component="desktop-tabs"', $html);
        self::assertStringContainsString('data-milpa-state="tabs"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // All four tabs, each bound to the shared `desktop.tab` signal for click and aria-selected.
        self::assertStringContainsString('data-tab="chat"', $html);
        self::assertStringContainsString('data-tab="work"', $html);
        self::assertStringContainsString('data-tab="activity"', $html);
        self::assertStringContainsString('data-tab="context"', $html);
        self::assertStringContainsString("\$store.milpa['desktop.tab'] = 'work'", $html);
        self::assertStringContainsString("\$store.milpa['desktop.tab'] === 'chat'", $html);
        // Conversation is the server-rendered default.
        self::assertStringContainsString('data-tab="chat" @click', $html);
        self::assertStringContainsString('aria-selected="true">Conversation', $html);
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(Tabs::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['tabs']->props['activeTab'] = 'work';
        });
        $events->subscribe(Tabs::AFTER_RENDER, static function (string $n, array $p): void {
            $p['tabs']->html .= '<!-- tabs extended -->';
        });

        $html = (new Tabs('secret', $events))->render();

        self::assertStringContainsString('tabs extended', $html, 'after_render changed the html');
        // before_render changed the active tab, so the server-rendered aria-selected lands on Work.
        self::assertStringContainsString('data-tab="work" @click="$store.milpa[\'desktop.tab\'] = \'work\'" :aria-selected="$store.milpa[\'desktop.tab\'] === \'work\'" aria-selected="true"', $html);
    }

    public function testTheComponentSelectActionDeclaresTheTabSignal(): void
    {
        $contract = TabsComponent::contract();
        self::assertSame('desktop-tabs', $contract->name);
        self::assertArrayHasKey('select', $contract->actions);

        $component = new TabsComponent();
        $state = $component->mount(['activeTab' => 'chat'], new ComponentContext('tabs'));
        $result = $component->handle(new InteractionRequest('tabs', 'desktop-tabs', 'select', $state, ['tab' => 'activity']));

        self::assertSame('activity', $result->state->data['activeTab']);
        self::assertSame([['type' => 'state', 'key' => 'desktop.tab', 'value' => 'activity']], $result->effects);
    }
}
