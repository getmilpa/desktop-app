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
use Milpa\Runtime\Http\CallbackStream;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The live feed, proved by execution through the real runtime (greenhouse decisions/0188, 0473): a plugin
 * dispatches {@see DesktopAppPlugin::CHANGED_EVENT} and `GET /desktop/events` streams it as SSE through a
 * {@see CallbackStream} the runtime's {@see ResponseEmitter} runs. A zero window means the stream writes the
 * backlog and closes without sleeping, so the emitted bytes are observable here; the live-tailing behavior
 * over an open connection is proved on cattle over a real socket.
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

    public function testAPluginsChangeIsStreamedThroughTheFeed(): void
    {
        $kernel = $this->boot();
        $this->dispatcher($kernel)->dispatch(
            DesktopAppPlugin::CHANGED_EVENT,
            ['shellEvent' => new ShellEvent('badge.updated', ['text' => 'hi'])],
        );

        $response = $this->get($kernel, '/desktop/events');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertInstanceOf(CallbackStream::class, $response->getBody());

        $body = $this->emit($response);
        self::assertStringContainsString('id: 1', $body);
        self::assertStringContainsString('event: badge.updated', $body);
        self::assertStringContainsString('data: {"text":"hi"}', $body);
    }

    public function testTheCursorStreamsEachEventExactlyOnce(): void
    {
        $kernel = $this->boot();
        $this->dispatcher($kernel)->dispatch(DesktopAppPlugin::CHANGED_EVENT, ['shellEvent' => new ShellEvent('first')]);

        // A client that already saw id 1 asks for what came after it: only the preamble, no first.
        self::assertStringNotContainsString('event: first', $this->emit($this->get($kernel, '/desktop/events?since=1')));

        $this->dispatcher($kernel)->dispatch(DesktopAppPlugin::CHANGED_EVENT, ['shellEvent' => new ShellEvent('second')]);
        $body = $this->emit($this->get($kernel, '/desktop/events?since=1'));
        self::assertStringContainsString('event: second', $body);
        self::assertStringNotContainsString('event: first', $body);
    }

    public function testTheCursorAlsoComesFromLastEventIdHeader(): void
    {
        $kernel = $this->boot();
        $this->dispatcher($kernel)->dispatch(DesktopAppPlugin::CHANGED_EVENT, ['shellEvent' => new ShellEvent('seen')]);

        $request = (new ServerRequest('GET', '/desktop/events'))->withHeader('Last-Event-ID', '1');
        $response = (new RequestHandler($kernel, new Psr17Factory()))->handle($request);
        self::assertStringNotContainsString('event: seen', $this->emit($response));
    }

    public function testItTailsWithinAShortWindowThenCloses(): void
    {
        // A tiny non-zero window exercises the tail loop (poll + re-read) without a slow test; with no new
        // events it simply re-polls until the window closes. The live delivery of events that appear DURING
        // the window is proved on cattle over a real socket.
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class],
            'config' => ['desktop' => ['events' => ['log' => $this->log, 'window_ms' => 5, 'poll_ms' => 1]]],
        ]);

        $body = $this->emit($this->get($kernel, '/desktop/events'));

        self::assertStringContainsString(': keep-alive', $body);
    }

    private function boot(): Kernel
    {
        return Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [DesktopAppPlugin::class],
            // Zero window: stream the backlog and close, without sleeping — observable in a test.
            'config' => ['desktop' => ['events' => ['log' => $this->log, 'window_ms' => 0, 'poll_ms' => 0]]],
        ]);
    }

    private function dispatcher(Kernel $kernel): MilpaEventDispatcherInterface
    {
        $dispatcher = $kernel->container()->get(MilpaEventDispatcherInterface::class);
        self::assertInstanceOf(MilpaEventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function get(Kernel $kernel, string $target): ResponseInterface
    {
        return (new RequestHandler($kernel, new Psr17Factory()))->handle(new ServerRequest('GET', $target));
    }

    /** Run the streaming body's callback and capture the bytes it writes (what the emitter runs in production). */
    private function emit(ResponseInterface $response): string
    {
        $body = $response->getBody();
        self::assertInstanceOf(CallbackStream::class, $body);

        ob_start();
        ($body->callback())();

        return (string) ob_get_clean();
    }
}
