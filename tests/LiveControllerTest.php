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

use Milpa\DesktopApp\Controllers\LiveController;
use Milpa\DesktopApp\Live\ComposerField;
use Milpa\Live\Http\LiveEndpoint;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The milpa/live transport under the Desktop (greenhouse decisions/0189, evidence/0490): the endpoint
 * verifies a signed state envelope + CSRF and re-renders the component; the asset routes serve the client.
 */
final class LiveControllerTest extends TestCase
{
    public function testItServesTheClientRuntimeAndAlpineFromThePackage(): void
    {
        $controller = new LiveController((new ComposerField('sign', 'csrf'))->endpoint());

        foreach (['client', 'alpine'] as $method) {
            $res = $controller->{$method}(new ServerRequest('GET', '/desktop/assets'));
            self::assertSame(200, $res->getStatusCode());
            self::assertStringContainsString('javascript', $res->getHeaderLine('Content-Type'));
            self::assertNotSame('', (string) $res->getBody());
        }
    }

    public function testItRoundTripsASignedInteraction(): void
    {
        $field = new ComposerField('sign-secret', 'csrf-secret');
        $controller = new LiveController($field->endpoint());

        // The signed state envelope the shell embeds, lifted from the initial render.
        self::assertSame(1, preg_match('#(<milpa-state\b.*?</milpa-state>)#s', $field->render(), $m));
        $envelope = $m[1];

        $sid = 'sess-test-1';
        $body = (string) json_encode([
            'action' => 'change',
            'state' => $envelope,
            'payload' => ['value' => 'Hola Milpa'],
        ]);
        $request = (new ServerRequest('POST', '/desktop/live', ['X-CSRF-Token' => $field->csrfToken($sid)], $body))
            ->withCookieParams([ComposerField::SESSION_COOKIE => $sid]);

        $res = $controller->live($request);
        self::assertSame(200, $res->getStatusCode());
        $decoded = json_decode((string) $res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['ok'] ?? false);
        self::assertSame('Hola Milpa', $decoded['data']['value'] ?? null, 'the interaction updated the component state');
        self::assertStringContainsString('Hola Milpa', (string) ($decoded['html'] ?? ''), 're-rendered HTML carries the value');
    }

    public function testItAcceptsTheCsrfTokenInTheBody(): void
    {
        // The framework's client runtime sends the CSRF token in the JSON body (not a header); the endpoint
        // must accept it there (greenhouse evidence/0491).
        $field = new ComposerField('sign-secret', 'csrf-secret');
        $controller = new LiveController($field->endpoint());

        self::assertSame(1, preg_match('#(<milpa-state\b.*?</milpa-state>)#s', $field->render(), $m));
        $sid = 'sess-body-1';
        $body = (string) json_encode(['action' => 'change', 'state' => $m[1], 'payload' => ['value' => 'x'], 'csrfToken' => $field->csrfToken($sid)]);
        $request = (new ServerRequest('POST', '/desktop/live', [], $body))->withCookieParams([ComposerField::SESSION_COOKIE => $sid]);

        $res = $controller->live($request);
        self::assertSame(200, $res->getStatusCode(), 'CSRF token in the body is accepted');
    }

    public function testAMalformedInteractionIsRejected(): void
    {
        $controller = new LiveController((new ComposerField('sign', 'csrf'))->endpoint());

        $res = $controller->live(new ServerRequest('POST', '/desktop/live', [], '{}'));

        self::assertSame(400, $res->getStatusCode());
        self::assertFalse((json_decode((string) $res->getBody(), true) ?: [])['ok'] ?? true);
    }

    public function testComposerFieldExposesItsEndpointAndCsrfToken(): void
    {
        $field = new ComposerField('sign', 'csrf');

        self::assertInstanceOf(LiveEndpoint::class, $field->endpoint());
        self::assertNotSame('', $field->csrfToken('sess-x'));
    }
}
