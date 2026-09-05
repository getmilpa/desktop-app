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

namespace Milpa\DesktopApp\Tests\Http;

use Milpa\DesktopApp\Http\LoopbackOnlyMiddleware;
use Milpa\DesktopApp\I18n\Catalog;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The Desktop's default gate (greenhouse decisions/0209): loopback passes, everything else is refused —
 * as a page for a browser, as JSON for the shell's own calls.
 */
final class LoopbackOnlyMiddlewareTest extends TestCase
{
    public function testLoopbackGetsThrough(): void
    {
        foreach (['127.0.0.1', '127.9.9.9', '::1', '::ffff:127.0.0.1'] as $address) {
            $response = (new LoopbackOnlyMiddleware())->process(self::request($address), self::handler());
            self::assertSame(200, $response->getStatusCode(), $address);
            self::assertSame('ok', (string) $response->getBody());
        }
    }

    public function testABrowserPageLoadFromTheLanGetsAnHtmlRefusal(): void
    {
        foreach (['203.0.113.9', '10.0.0.1', '', '127.0.0.1.evil', 'localhost'] as $address) {
            $response = (new LoopbackOnlyMiddleware())->process(
                self::request($address)->withHeader('Accept', 'text/html,application/xhtml+xml,*/*;q=0.8'),
                self::handler(),
            );
            self::assertSame(403, $response->getStatusCode(), $address === '' ? '(empty)' : $address);
            self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
            $body = (string) $response->getBody();
            self::assertStringContainsString('<!doctype html>', $body);
            self::assertStringContainsString('Loopback only', $body);
            self::assertStringContainsString('desktop.middleware', $body, 'the page names the key to declare');
        }
    }

    public function testEverythingElseFromTheLanGetsAJsonRefusal(): void
    {
        // The shell's own fetch() calls accept */*; a POST is never a page load, whatever it accepts.
        $cases = [
            self::request('203.0.113.9'),
            self::request('203.0.113.9')->withHeader('Accept', 'application/json'),
            self::request('203.0.113.9')->withHeader('Accept', '*/*'),
            (new ServerRequest('POST', '/desktop/settings', ['Accept' => 'text/html'], null, '1.1', ['REMOTE_ADDR' => '203.0.113.9'])),
        ];
        foreach ($cases as $request) {
            $response = (new LoopbackOnlyMiddleware())->process($request, self::handler());
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame(['ok' => false, 'error' => 'loopback_only'], json_decode((string) $response->getBody(), true));
        }
    }

    public function testTheRefusalSpeaksTheDeclaredLocaleAndANonStringAddressIsNobody(): void
    {
        $response = (new LoopbackOnlyMiddleware(new Catalog('es')))->process(
            self::request('10.0.0.1')->withHeader('Accept', 'text/html'),
            self::handler(),
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('lang="es"', (string) $response->getBody());
        self::assertStringContainsString('Sólo loopback', (string) $response->getBody());

        $odd = new ServerRequest('GET', '/desktop', [], null, '1.1', ['REMOTE_ADDR' => ['127.0.0.1']]);
        self::assertSame(403, (new LoopbackOnlyMiddleware())->process($odd, self::handler())->getStatusCode(), 'an address that is not a string fails closed');
    }

    private static function request(string $address): ServerRequestInterface
    {
        return new ServerRequest('GET', '/desktop', [], null, '1.1', $address === '' ? [] : ['REMOTE_ADDR' => $address]);
    }

    private static function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
    }
}
