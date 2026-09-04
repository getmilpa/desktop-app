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

use Milpa\DesktopApp\Live\MessagePrototypes;
use Milpa\DesktopApp\Live\SystemNoticeComponent;
use Milpa\DesktopApp\Live\TaskComponent;
use Milpa\DesktopApp\Live\ToolCallComponent;
use Milpa\DesktopApp\Live\UserMessageComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The plainer message types — user, tool, task, system — are milpa/live components too (greenhouse
 * decisions/0191): each a declared contract rendered as a signed prototype the conversation clones, each
 * extensible by hooking its render events.
 */
final class MessagePrototypesTest extends TestCase
{
    public function testEachPrototypeIsAComponentWithASignedEnvelopeAndRegions(): void
    {
        $p = new MessagePrototypes('secret');

        $user = $p->user();
        self::assertStringContainsString('data-milpa-component="desktop-user-message"', $user);
        self::assertStringContainsString('data-milpa-state="user-message"', $user);
        self::assertStringContainsString('data-user-body', $user);

        $tool = $p->tool();
        self::assertStringContainsString('data-milpa-component="desktop-tool-call"', $tool);
        self::assertStringContainsString('data-tool-name', $tool);
        self::assertStringContainsString('data-tool-result', $tool);

        $task = $p->task();
        self::assertStringContainsString('data-milpa-component="desktop-task"', $task);
        self::assertStringContainsString('data-task-title', $task);
        self::assertStringContainsString('data-task-status', $task);

        $system = $p->system();
        self::assertStringContainsString('data-milpa-component="desktop-system-notice"', $system);
        self::assertStringContainsString('data-system-body', $system);
        self::assertStringContainsString('security="signed"', $system);
    }

    public function testEachPrototypeEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(MessagePrototypes::USER_AFTER, static function (string $n, array $p): void {
            $p['userMessage']->html .= '<!-- user extended -->';
        });
        $events->subscribe(MessagePrototypes::TOOL_AFTER, static function (string $n, array $p): void {
            $p['toolCall']->html .= '<!-- tool extended -->';
        });

        $p = new MessagePrototypes('secret', $events);

        self::assertStringContainsString('user extended', $p->user());
        self::assertStringContainsString('tool extended', $p->tool());
    }

    public function testTheContractsAreDeclaredAndInert(): void
    {
        self::assertSame('desktop-user-message', UserMessageComponent::contract()->name);
        self::assertSame('desktop-tool-call', ToolCallComponent::contract()->name);
        self::assertSame('desktop-task', TaskComponent::contract()->name);
        self::assertSame('desktop-system-notice', SystemNoticeComponent::contract()->name);

        $tool = new ToolCallComponent();
        $state = $tool->mount(['name' => 'capabilities.list', 'result' => '6 capabilities'], new ComponentContext('tool-call'));
        self::assertSame('capabilities.list', $state->data['name']);
        self::assertSame('6 capabilities', $state->meta['result']);
    }
}
