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

use Milpa\DesktopApp\Live\Gate;
use Milpa\DesktopApp\Live\GateComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The consent gate is the shell's seventh (last) pure-Milpa-Components surface (greenhouse decisions/0189): a
 * declared component whose visibility is the shared `desktop.gate.open` signal; its content fills live.
 */
final class GateTest extends TestCase
{
    public function testItRendersAsAHiddenSignalDrivenMilpaLiveComponent(): void
    {
        $html = (new Gate('secret'))->render();

        self::assertStringContainsString('data-milpa-component="desktop-gate"', $html);
        self::assertStringContainsString('data-milpa-state="gate"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // Hidden by default (server) and its visibility bound to the shared signal.
        self::assertStringContainsString('id="milpa-gate"', $html);
        self::assertStringContainsString("!\$store.milpa['desktop.gate.open']", $html);
        // Dismiss sets the signal false; the live fill hooks are preserved.
        self::assertStringContainsString("\$store.milpa['desktop.gate.open'] = false", $html);
        self::assertStringContainsString('data-gate-op', $html);
        self::assertStringContainsString('data-gate-args', $html);
        self::assertStringContainsString('data-gate-action', $html);
        self::assertStringContainsString('data-gate-approve', $html);
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(Gate::AFTER_RENDER, static function (string $n, array $p): void {
            $p['gate']->html .= '<!-- gate extended -->';
        });

        $html = (new Gate('secret', $events))->render();

        self::assertStringContainsString('gate extended', $html, 'after_render changed the html');
    }

    public function testTheComponentDismissActionDeclaresTheOpenSignalFalse(): void
    {
        $contract = GateComponent::contract();
        self::assertSame('desktop-gate', $contract->name);
        self::assertArrayHasKey('dismiss', $contract->actions);

        $component = new GateComponent();
        $state = $component->mount(['open' => true], new ComponentContext('gate'));
        self::assertTrue($state->data['open']);

        $result = $component->handle(new InteractionRequest('gate', 'desktop-gate', 'dismiss', $state, []));
        self::assertFalse($result->state->data['open']);
        self::assertSame([['type' => 'state', 'key' => 'desktop.gate.open', 'value' => false]], $result->effects);
    }
}
