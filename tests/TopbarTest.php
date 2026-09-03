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
use Milpa\DesktopApp\Live\Topbar;
use Milpa\DesktopApp\Live\TopbarComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The topbar is the shell's second pure-Milpa-Components surface (greenhouse decisions/0189): a declared
 * component with a signed envelope, lifecycle events, and badges bound to shared signals.
 */
final class TopbarTest extends TestCase
{
    public function testItRendersAsAMilpaLiveComponentBoundToSharedSignals(): void
    {
        $html = (new Topbar('secret'))->render();

        // A real component: the root declares it, and the signed state envelope rides along.
        self::assertStringContainsString('data-milpa-component="desktop-topbar"', $html);
        self::assertStringContainsString('data-milpa-state="topbar"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // The badges read shared signals (one truth with the composer's chip and the session state).
        self::assertStringContainsString("\$store.milpa['session.state.label']", $html);
        self::assertStringContainsString("\$store.milpa['composer.mode.label']", $html);
        // No session open → the empty-workspace note, and Export still points at the export route.
        self::assertStringContainsString('No session open', $html);
        self::assertStringContainsString('href="/desktop/export"', $html);
    }

    public function testAnActiveSessionShowsItsGoalAndId(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-topbar-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/bbb22222.json', json_encode(['goal' => 'Ship the topbar', 'state' => 'working'], JSON_THROW_ON_ERROR));
        $data = new DesktopData(new DIContainer(), null, $dir);

        $html = (new Topbar('secret', $data))->render();

        self::assertStringContainsString('Ship the topbar', $html);
        self::assertStringContainsString('session bbb22222', $html);

        unlink($dir . '/bbb22222.json');
        rmdir($dir);
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(Topbar::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['topbar']->props['goal'] = 'Injected goal';
            $p['topbar']->props['hasSession'] = true;
        });
        $events->subscribe(Topbar::AFTER_RENDER, static function (string $n, array $p): void {
            $p['topbar']->html .= '<!-- topbar extended -->';
        });

        $html = (new Topbar('secret', null, $events))->render();

        self::assertStringContainsString('topbar extended', $html, 'after_render changed the html');
        self::assertStringContainsString('Injected goal', $html, 'before_render changed the props');
    }

    public function testTheContractIsAProjectionSurfaceWithNoActions(): void
    {
        $contract = TopbarComponent::contract();
        self::assertSame('desktop-topbar', $contract->name);
        self::assertSame([], $contract->actions);

        $component = new TopbarComponent();
        $state = $component->mount(['state' => 'working', 'mode' => 'Continue automatically', 'hasSession' => true], new ComponentContext('topbar'));
        self::assertSame('working', $state->data['state']);
        self::assertSame('Continue automatically', $state->data['mode']);
    }
}
