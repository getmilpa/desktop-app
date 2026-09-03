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

use Milpa\DesktopApp\Live\Activity;
use Milpa\DesktopApp\Live\ActivityComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Activity tab is the shell's fifth pure-Milpa-Components surface (greenhouse decisions/0189): a projection
 * component (live fact stream + counter projection) with a signed envelope and lifecycle events.
 */
final class ActivityTest extends TestCase
{
    public function testItRendersTheStreamAndProjectionAsAMilpaLiveComponent(): void
    {
        $html = (new Activity('secret'))->render();

        self::assertStringContainsString('data-milpa-component="desktop-activity"', $html);
        self::assertStringContainsString('data-milpa-state="activity"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // The live stream keeps its id (the event client prepends facts to it) and the empty state.
        self::assertStringContainsString('id="milpa-activity"', $html);
        self::assertStringContainsString('no facts recorded yet', $html);
        // The counter projection is present (default counters: state idle).
        self::assertStringContainsString('mui-replay__projection', $html);
        self::assertStringContainsString('mui-replay__stat', $html);
        // display:contents keeps the two children as grid items of the pane.
        self::assertStringContainsString('style="display:contents"', $html);
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(Activity::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['activity']->props['audit'] = [['seq' => 7, 'type' => 'gate.opened', 'data' => 'devtools']];
        });
        $events->subscribe(Activity::AFTER_RENDER, static function (string $n, array $p): void {
            $p['activity']->html .= '<!-- activity extended -->';
        });

        $html = (new Activity('secret', null, $events))->render();

        self::assertStringContainsString('activity extended', $html, 'after_render changed the html');
        self::assertStringContainsString('gate.opened', $html, 'before_render changed the props');
        self::assertStringContainsString('seq 7', $html);
    }

    public function testTheContractIsAProjectionSurfaceWithNoActions(): void
    {
        $contract = ActivityComponent::contract();
        self::assertSame('desktop-activity', $contract->name);
        self::assertSame([], $contract->actions);

        $component = new ActivityComponent();
        $state = $component->mount(['audit' => [['seq' => 1, 'type' => 't', 'data' => 'd']], 'projection' => []], new ComponentContext('activity'));
        self::assertSame(1, $state->data['facts']);
    }
}
