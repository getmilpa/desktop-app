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

namespace Milpa\DesktopApp\Tests\Controllers;

use Milpa\Container\DIContainer;
use Milpa\DesktopApp\Controllers\DataController;
use Milpa\DesktopApp\Data\DesktopData;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The data snapshot as JSON (greenhouse decisions/0481): what the screens consume, served for a client that
 * refreshes without a reload.
 */
final class DataControllerTest extends TestCase
{
    public function testItServesTheSnapshotAsJson(): void
    {
        $controller = new DataController(new DesktopData(new DIContainer()));

        $res = $controller->data(new ServerRequest('GET', '/desktop/data.json'));

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('capabilities', $decoded);
        self::assertArrayHasKey('model', $decoded);
    }
}
