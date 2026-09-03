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

use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\Live\ShellEvent;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The live feed, proved by execution through the real runtime (greenhouse decisions/0188): a plugin
 * dispatches {@see DesktopAppPlugin::CHANGED_EVENT} in one turn, and `GET /desktop/events` serves it as
 * SSE in another — the cross-request push a live UI needs, carried by the shared log. The cursor
 * (Last-Event-ID / `?since=`) is what a reconnecting EventSource uses to receive each event exactly once.
 */
final class LiveFeedTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        $this->log = sys_get_temp_dir() . '/milpa-desktop-feed-' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->log)) {
            unlink($this->log);
        }
    }

    public function testAPluginsChangePushesThroughTheFeed(): void
    {
        $kernel = $this->boot();
        $dispatcher = $kernel->container()->get(MilpaEventDispatcherInterface::class);
        self::assertInstanceOf(MilpaEventDispatcherInterface::class, $dispatcher);

        // A plugin announces a shell change (the write happens in this "request").
        $dispatcher->dispatch(DesktopAppPlugin::CHANGED_EVENT, ['shellEvent' => new ShellEvent('badge.updated', ['text' => 'hi'])]);

        $response = $this->get($kernel, '/desktop/events');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('id: 1', $body);
        self::assertStringContainsString('event: badge.updated', $body);
        self::assertStringContainsString('data: {"text":"hi"}', $body);
    }

    public function testTheCursorDeliversEachEventExactlyOnce(): void
    {
        $kernel = $this->boot();
        $dispatcher = $kernel->container()->get(MilpaEventDispatcherInterface::class);
        self::assertInstanceOf(MilpaEventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(DesktopAppPlugin::CHANGED_EVENT, ['shellEvent' => new ShellEvent('first')]);

        // A client that already saw event id 1 asks for what came after it: nothing yet.
        self::assertStringNotContainsString('event: first', (string) $this->get($kernel, '/desktop/events?since=1')->getBody());

        // A new change arrives; the same cursor now delivers only it.
        $dispatcher->dispatch(DesktopAppPlugin::CHANGED_EVENT, ['shellEvent' => new ShellEvent('second')]);
        $body = (string) $this->get($kernel, '/desktop/events?since=1')->getBody();
        self::assertStringContainsString('event: second', $body);
        self::assertStringNotContainsString('event: first', $body);
    }

    public function testTheCursorAlsoComesFromLastEventIdHeader(): void
    {
        $kernel = $this->boot();
        $dispatcher = $kernel->container()->get(MilpaEventDispatcherInterface::class);
        self::assertInstanceOf(MilpaEventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(DesktopAppPlugin::CHANGED_EVENT, ['shellEvent' => new ShellEvent('seen')]);

        // EventSource sends Last-Event-ID on reconnect; the feed honors it like ?since=.
        $request = (new ServerRequest('GET', '/desktop/events'))->withHeader('Last-Event-ID', '1');
        $body = (string) (new RequestHandler($kernel, new Psr17Factory()))->handle($request)->getBody();
        self::assertStringNotContainsString('event: seen', $body);
    }

    private function boot(): Kernel
    {
        return Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class],
            'config' => ['desktop' => ['events' => ['log' => $this->log]]],
        ]);
    }

    private function get(Kernel $kernel, string $target): \Psr\Http\Message\ResponseInterface
    {
        return (new RequestHandler($kernel, new Psr17Factory()))->handle(new ServerRequest('GET', $target));
    }
}
