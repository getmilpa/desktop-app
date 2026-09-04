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

use Milpa\DesktopApp\Live\AgentMessage;
use Milpa\DesktopApp\Live\AgentMessageComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The agent's message is a Milpa Component carrying its foot tools (greenhouse decisions/0191): copy the
 * answer, regenerate it. A plugin adds more tools to every agent message by hooking its render events.
 */
final class AgentMessageTest extends TestCase
{
    public function testItRendersAPrototypeWithTheBodyAndTheFootTools(): void
    {
        $html = (new AgentMessage('secret'))->render();

        self::assertStringContainsString('data-milpa-component="desktop-agent-message"', $html);
        self::assertStringContainsString('data-milpa-state="agent-message"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // The answer's region and the foot tools the conversation acts on through a delegated handler.
        self::assertStringContainsString('data-agent-body', $html);
        self::assertStringContainsString('data-agent-copy', $html);
        self::assertStringContainsString('data-agent-regenerate', $html);
        self::assertStringContainsString('class="msg__tools"', $html);
        self::assertStringContainsString('aria-label="Copy response"', $html);
        self::assertStringContainsString('aria-label="Regenerate response"', $html);
    }

    public function testItEmitsRenderEventsSoPluginsCanAddTools(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(AgentMessage::AFTER_RENDER, static function (string $n, array $p): void {
            $p['agentMessage']->html .= '<!-- agent message extended -->';
        });

        $html = (new AgentMessage('secret', $events))->render();

        self::assertStringContainsString('agent message extended', $html, 'after_render changed the html');
    }

    public function testTheContractDeclaresTheCopyAndRegenerateTools(): void
    {
        $contract = AgentMessageComponent::contract();
        self::assertSame('desktop-agent-message', $contract->name);
        self::assertArrayHasKey('copy', $contract->actions);
        self::assertArrayHasKey('regenerate', $contract->actions);

        $component = new AgentMessageComponent();
        $state = $component->mount(['text' => 'the answer'], new ComponentContext('agent-message'));
        self::assertSame('the answer', $state->data['text']);
    }
}
