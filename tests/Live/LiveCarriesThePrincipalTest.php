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

use Milpa\DesktopApp\Controllers\LiveController;
use Milpa\DesktopApp\Http\RequestPrincipal;
use Milpa\DesktopApp\Live\DesktopComponents;
use Milpa\DesktopApp\Tests\Fixtures\PasskeyGateStub;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Identity must travel with an ACTION, not only with the paint.
 *
 * `LiveEndpoint::handle()` takes a principal as its second argument, and this adapter passed none while
 * `ShellController` read one from the same request. So the Desktop knew who you were when it PAINTED and
 * forgot when you ACTED.
 *
 * MEASURED, and not what it first looked like. The ownership check in `ContractInteractionAuthorizer` was
 * not switched off — it was stuck on DENY: state naming an owner was refused to everyone, the owner
 * included, because the caller was always `null`. That is why no component in this package mounts
 * `meta['principal']`: per-principal state could not work, so nobody wrote any.
 *
 * The middle case below is the falsifier — it is the one that fails on the version that passed nothing.
 * The other two are guards: they held before and must keep holding, which is what makes the fix additive
 * rather than a loosening.
 */
#[CoversClass(LiveController::class)]
final class LiveCarriesThePrincipalTest extends TestCase
{
    public function testStateMintedForOnePrincipalIsRefusedToAnother(): void
    {
        [$controller, $registry] = $this->desktop();

        $response = $controller->live($this->interaction($registry, 'someone-else'));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('principal', (string) $response->getBody());
    }

    public function testStateMintedForAPrincipalIsAcceptedFromThatPrincipal(): void
    {
        // THE FALSIFIER. Before this adapter passed a principal, the owner was refused their OWN state:
        // the check compares against a caller that was always null, so it could only ever deny.
        [$controller, $registry] = $this->desktop();

        $response = $controller->live($this->interaction($registry, PasskeyGateStub::PRINCIPAL));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnAnonymousCallerCannotDriveStateThatNamesAPrincipal(): void
    {
        // A GUARD, not the falsifier: this already denied, and must keep denying. Passing a principal
        // must not open a door that was shut.
        [$controller, $registry] = $this->desktop();

        $response = $controller->live($this->interaction($registry, null));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('principal', (string) $response->getBody());
    }

    /** @return array{0: LiveController, 1: DesktopComponents} */
    private function desktop(): array
    {
        $registry = new DesktopComponents('sign-secret', 'csrf-secret');
        $registry->declare(new OwnedComponent(), static fn (array $props): string => '<p>owned</p>');

        return [new LiveController($registry->endpoint()), $registry];
    }

    /** A signed interaction on state minted for {@see PasskeyGateStub::PRINCIPAL}, seen by `$caller`. */
    private function interaction(DesktopComponents $registry, ?string $caller): ServerRequest
    {
        $state = (new OwnedComponent())->mount(
            ['principal' => PasskeyGateStub::PRINCIPAL],
            new ComponentContext('owned-1', DesktopComponents::ROUTE),
        );

        $sid = 'sess-owned-1';
        $body = (string) json_encode([
            'action' => 'touch',
            'state' => $registry->codec()->encodeState($state),
            'payload' => [],
            'sessionId' => $sid,
        ]);

        $request = new ServerRequest(
            'POST',
            DesktopComponents::ROUTE,
            ['X-CSRF-Token' => $registry->csrfToken($sid)],
            $body,
        );

        return $caller === null
            ? $request
            : $request->withAttribute(RequestPrincipal::ATTRIBUTE, PasskeyGateStub::context($caller));
    }
}

/** A component whose signed state NAMES its owner — the shape the ownership check exists for. */
final class OwnedComponent implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-owned',
            contractVersion: '1',
            summary: 'A fixture component whose state names the principal it was minted for.',
            actions: ['touch' => []],
        );
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-owned',
            '1',
            ['touched' => false],
            ['principal' => (string) ($props['principal'] ?? '')],
        );
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
