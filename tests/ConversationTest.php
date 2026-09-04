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

use Milpa\DesktopApp\Live\Conversation;
use Milpa\DesktopApp\Live\ConversationComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The conversation is a Milpa Component that composes the message components (greenhouse decisions/0191): the
 * chat is a component made of other components. Its empty state and container are its own; a plugin extends the
 * thread by hooking its render events.
 */
final class ConversationTest extends TestCase
{
    public function testItRendersItsEmptyStateWithASignedEnvelope(): void
    {
        $html = (new Conversation('secret'))->render();

        self::assertStringContainsString('data-milpa-state="conversation"', $html);
        self::assertStringContainsString('security="signed"', $html);
        self::assertStringContainsString('milpa-empty-convo', $html);
        self::assertStringContainsString('No messages yet', $html);
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendTheThread(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(Conversation::AFTER_RENDER, static function (string $n, array $p): void {
            $p['conversation']->html .= '<!-- conversation extended -->';
        });

        $html = (new Conversation('secret', $events))->render();

        self::assertStringContainsString('conversation extended', $html, 'after_render changed the html');
    }

    public function testTheContractIsAContainer(): void
    {
        $contract = ConversationComponent::contract();
        self::assertSame('desktop-conversation', $contract->name);
        self::assertSame([], $contract->actions);

        $component = new ConversationComponent();
        $state = $component->mount(['empty' => true], new ComponentContext('conversation'));
        self::assertTrue($state->data['empty']);
    }
}
