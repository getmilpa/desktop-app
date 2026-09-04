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

use Milpa\DesktopApp\Live\Thinking;
use Milpa\DesktopApp\Live\ThinkingComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The agent's thinking is a conversation message made a real Milpa Component (greenhouse decisions/0191): the
 * conversation clones its server-rendered prototype and feeds it live; its open/collapse behaviour is the
 * component's own (declared Alpine), not hand-wired JS.
 */
final class ThinkingTest extends TestCase
{
    public function testItRendersAPrototypeWithDeclaredBehaviourAndASignedEnvelope(): void
    {
        $html = (new Thinking('secret'))->render();

        self::assertStringContainsString('data-milpa-component="desktop-thinking"', $html);
        self::assertStringContainsString('data-milpa-state="thinking"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // Behaviour is the component's own, declared in markup + CSS: the collapse rides `data-open`, and the
        // conversation feeds the instance through its regions.
        self::assertStringContainsString('data-open="1"', $html);
        self::assertStringContainsString('data-thinking-toggle', $html);
        self::assertStringContainsString('data-thinking-head', $html);
        self::assertStringContainsString('data-thinking-body', $html);
        // The block starts LIVE (data-thinking-active="1") — the animation keys off it — and its head is a
        // breathing spark + a label + typing dots, so the elapsed can replace only the words, never the motion.
        self::assertStringContainsString('data-thinking-active="1"', $html);
        self::assertStringContainsString('data-thinking-spark', $html);
        self::assertStringContainsString('◈', $html);
        self::assertStringContainsString('data-thinking-label', $html);
        self::assertStringContainsString('thinking', $html);
        self::assertStringContainsString('milpa-think__dots', $html);
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(Thinking::AFTER_RENDER, static function (string $n, array $p): void {
            $p['thinking']->html .= '<!-- thinking extended -->';
        });

        $html = (new Thinking('secret', $events))->render();

        self::assertStringContainsString('thinking extended', $html, 'after_render changed the html');
    }

    public function testTheComponentToggleFlipsOpen(): void
    {
        $contract = ThinkingComponent::contract();
        self::assertSame('desktop-thinking', $contract->name);
        self::assertArrayHasKey('toggle', $contract->actions);

        $component = new ThinkingComponent();
        $state = $component->mount([], new ComponentContext('thinking'));
        self::assertTrue($state->data['open']);
        self::assertFalse($state->data['done']);

        $result = $component->handle(new InteractionRequest('thinking', 'desktop-thinking', 'toggle', $state, []));
        self::assertFalse($result->state->data['open'], 'toggle closes it');
    }
}
