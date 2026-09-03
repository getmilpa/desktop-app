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

namespace Milpa\DesktopApp\Tests\Live;

use Milpa\DesktopApp\Live\MercurePublisher;
use Milpa\DesktopApp\Live\ShellEvent;
use Milpa\Mercure\MercureService;
use PHPUnit\Framework\TestCase;

/**
 * The adapter publishes a shell event to the hub on its topic (greenhouse decisions/0475): the event's type
 * and data become the Mercure update. MercureService itself is graduated against a real hub in evidence/0474;
 * here it is a double, so this asserts only that the adapter calls it correctly.
 */
final class MercurePublisherTest extends TestCase
{
    public function testItPublishesTheEventTypeAndDataOnItsTopic(): void
    {
        $mercure = $this->createMock(MercureService::class);
        $mercure->expects(self::once())
            ->method('publish')
            ->with('desktop/shell', ['event' => 'badge.updated', 'data' => ['text' => 'hi']]);

        (new MercurePublisher($mercure, 'desktop/shell'))->publish(new ShellEvent('badge.updated', ['text' => 'hi']));
    }
}
