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

use Milpa\DesktopApp\Controllers\MutationController;
use Milpa\DesktopApp\Data\DesktopStore;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The write endpoints persist the posted change (greenhouse decisions/0483) and return JSON.
 */
final class MutationControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-mut-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/sessions/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir . '/sessions')) {
            rmdir($this->dir . '/sessions');
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir);
    }

    private function controller(): MutationController
    {
        return new MutationController(new DesktopStore($this->dir . '/sessions', $this->dir . '/settings.json'));
    }

    public function testPostSettingsPersistsThem(): void
    {
        $request = (new ServerRequest('POST', '/desktop/settings'))
            ->withBody(\Nyholm\Psr7\Stream::create(json_encode(['endpoint' => 'http://x/v1'], JSON_THROW_ON_ERROR)));

        $res = $this->controller()->saveSettings($request);

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('"ok":true', (string) $res->getBody());
        self::assertStringContainsString('http://x/v1', (string) file_get_contents($this->dir . '/settings.json'));
    }

    public function testPostSessionsCreatesOneAndReturnsItsId(): void
    {
        $request = (new ServerRequest('POST', '/desktop/sessions'))
            ->withBody(\Nyholm\Psr7\Stream::create(json_encode(['goal' => 'Do the thing'], JSON_THROW_ON_ERROR)));

        $res = $this->controller()->createSession($request);
        $decoded = json_decode((string) $res->getBody(), true);

        self::assertSame(200, $res->getStatusCode());
        self::assertIsArray($decoded);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $decoded['id']);
        self::assertFileExists($this->dir . '/sessions/' . $decoded['id'] . '.json');
    }

    public function testPostWorkMovesAnItem(): void
    {
        mkdir($this->dir . '/sessions');
        file_put_contents($this->dir . '/sessions/s1.json', json_encode(['work' => [['title' => 'a', 'status' => 'pending']]], JSON_THROW_ON_ERROR));

        $request = (new ServerRequest('POST', '/desktop/work'))
            ->withBody(\Nyholm\Psr7\Stream::create(json_encode(['session' => 's1', 'index' => 0, 'status' => 'done'], JSON_THROW_ON_ERROR)));

        $res = $this->controller()->moveWork($request);

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('"ok":true', (string) $res->getBody());
        self::assertStringContainsString('"status": "done"', (string) file_get_contents($this->dir . '/sessions/s1.json'));
    }

    public function testPostWorkWithAnUnknownSessionReportsNotOk(): void
    {
        $request = (new ServerRequest('POST', '/desktop/work'))
            ->withBody(\Nyholm\Psr7\Stream::create(json_encode(['session' => 'nope', 'index' => 0, 'status' => 'done'], JSON_THROW_ON_ERROR)));

        self::assertStringContainsString('"ok":false', (string) $this->controller()->moveWork($request)->getBody());
    }

    public function testAMalformedBodyIsToleratedAsEmpty(): void
    {
        $request = (new ServerRequest('POST', '/desktop/settings'))
            ->withBody(\Nyholm\Psr7\Stream::create('not json'));

        $res = $this->controller()->saveSettings($request);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('[]', trim((string) file_get_contents($this->dir . '/settings.json')));
    }
}
