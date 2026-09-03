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
use Milpa\DesktopApp\Live\WorkBoard;
use Milpa\DesktopApp\Live\WorkBoardComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Work board is the shell's fourth pure-Milpa-Components surface (greenhouse decisions/0189): a projection
 * component with a signed envelope and lifecycle events. Drag-drop still persists via /desktop/work (0484).
 */
final class WorkBoardTest extends TestCase
{
    public function testItRendersItemsAsAMilpaLiveComponentGroupedByStatus(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-work-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/ccc33333.json', json_encode([
            'goal' => 'Board goal',
            'work' => [
                ['title' => 'Draft the plan', 'status' => 'pending', 'origin' => 'planned'],
                ['title' => 'Ship the board', 'status' => 'done', 'origin' => 'agent'],
            ],
        ], JSON_THROW_ON_ERROR));
        $data = new DesktopData(new DIContainer(), null, $dir);

        $html = (new WorkBoard('secret', $data))->render();

        self::assertStringContainsString('data-milpa-component="desktop-work-board"', $html);
        self::assertStringContainsString('data-milpa-state="work-board"', $html);
        self::assertStringContainsString('security="signed"', $html);
        // The board with all four columns, the session, and draggable cards for the two items.
        self::assertStringContainsString('class="work-board"', $html);
        self::assertStringContainsString('data-session="ccc33333"', $html);
        self::assertStringContainsString('data-status="pending"', $html);
        self::assertStringContainsString('data-status="done"', $html);
        self::assertStringContainsString('Draft the plan', $html);
        self::assertStringContainsString('Ship the board', $html);
        self::assertSame(2, substr_count($html, 'draggable="true"'));

        unlink($dir . '/ccc33333.json');
        rmdir($dir);
    }

    public function testEmptyWorkShowsTheEmptyStateStillAsAComponent(): void
    {
        $html = (new WorkBoard('secret'))->render();

        self::assertStringContainsString('data-milpa-component="desktop-work-board"', $html);
        self::assertStringContainsString('No work board yet', $html);
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(WorkBoard::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['workBoard']->props['work'] = [['title' => 'Injected item', 'status' => 'blocked', 'origin' => 'plugin']];
        });
        $events->subscribe(WorkBoard::AFTER_RENDER, static function (string $n, array $p): void {
            $p['workBoard']->html .= '<!-- work board extended -->';
        });

        $html = (new WorkBoard('secret', null, $events))->render();

        self::assertStringContainsString('work board extended', $html, 'after_render changed the html');
        self::assertStringContainsString('Injected item', $html, 'before_render changed the props');
    }

    public function testTheContractIsAProjectionSurfaceWithNoActions(): void
    {
        $contract = WorkBoardComponent::contract();
        self::assertSame('desktop-work-board', $contract->name);
        self::assertSame([], $contract->actions);

        $component = new WorkBoardComponent();
        $state = $component->mount(['work' => [['title' => 't', 'status' => 'pending', 'origin' => 'o']], 'sessionId' => 'abc'], new ComponentContext('work-board'));
        self::assertSame(1, $state->data['count']);
    }
}
