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

use Milpa\DesktopApp\Http\RequestPrincipal;
use Milpa\DesktopApp\Tests\Fixtures\PasskeyGateStub;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/** Who the gate let in, read by duck-typing and failing closed (greenhouse decisions/0209). */
final class RequestPrincipalTest extends TestCase
{
    public function testTheAuthenticatedActorsIdIsThePrincipalWhicheverShapeTheContextHas(): void
    {
        self::assertSame('milpa.auth', RequestPrincipal::ATTRIBUTE, 'the attribute milpa/auth\'s AuthenticateMiddleware leaves its context under');
        self::assertSame('passkey:rod', RequestPrincipal::of(self::with(PasskeyGateStub::context('passkey:rod'))), 'public actor, public id — milpa/auth\'s own shape');

        $accessors = new class () {
            public function isAuthenticated(): bool
            {
                return true;
            }

            public function actor(): object
            {
                return new class () {
                    public function id(): string
                    {
                        return 'agent:qwen';
                    }
                };
            }
        };
        self::assertSame('agent:qwen', RequestPrincipal::of(self::with($accessors)), 'a context that answers through methods is read the same');
    }

    public function testEverythingElseIsNobody(): void
    {
        self::assertNull(RequestPrincipal::of(new ServerRequest('GET', '/desktop')), 'no gate ran: no attribute');
        self::assertNull(RequestPrincipal::of(self::with('passkey:rod')), 'a bare string is not a context — never trusted');
        self::assertNull(RequestPrincipal::of(self::with(['actor' => ['id' => 'passkey:rod']])), 'nor an array');
        self::assertNull(RequestPrincipal::of(self::with(new \stdClass())), 'an object that cannot say it is authenticated is not');

        $anonymous = new class () {
            public ?object $actor = null;

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($anonymous)), 'anonymous: fail closed');

        $liar = new class () {
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($liar)), 'authenticated with no actor at all: nobody');

        $blank = new class () {
            public object $actor;

            public function __construct()
            {
                $this->actor = new class () {
                    public string $id = '';
                };
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($blank)), 'an empty id is not a principal');

        $numeric = new class () {
            public object $actor;

            public function __construct()
            {
                $this->actor = new class () {
                    public int $id = 42;
                };
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($numeric)), 'an id that is not a string is not rendered');

        $scalarActor = new class () {
            public string $actor = 'passkey:rod';

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($scalarActor)), 'an actor that is not an object has no id to read');
    }

    /**
     * The reader never calls a method it cannot: a member answered by a method is read only when the method
     * is public and needs no argument, and a call that throws is nobody — never a 500 in front of the shell.
     */
    public function testAMethodTheReaderCannotCallIsNobody(): void
    {
        $needsAnArgument = new class () {
            public function isAuthenticated(): bool
            {
                return true;
            }

            public function actor(string $which): object
            {
                return new class () {
                    public string $id = 'passkey:rod';
                };
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($needsAnArgument)), 'actor() requires an argument the Desktop does not have: never called, nobody');

        $throws = new class () {
            public function isAuthenticated(): bool
            {
                return true;
            }

            public function actor(): object
            {
                throw new \RuntimeException('the store is gone');
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($throws)), 'a call that throws reads as nobody, and the shell keeps serving');

        $hidden = new class () {
            public function isAuthenticated(): bool
            {
                return true;
            }

            public function actor(): object
            {
                return new class () {
                    protected function id(): string
                    {
                        return 'passkey:rod';
                    }
                };
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($hidden)), 'a protected id() is not a member the Desktop can read');

        $guardedVerdict = new class () {
            public object $actor;

            public function __construct()
            {
                $this->actor = new class () {
                    public string $id = 'passkey:rod';
                };
            }

            public function isAuthenticated(string $strictly): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($guardedVerdict)), 'the verdict itself is read the same way: an isAuthenticated() that needs an argument is never called');

        $optional = new class () {
            public function isAuthenticated(): bool
            {
                return true;
            }

            public function actor(?string $which = null): object
            {
                return new class () {
                    public string $id = 'passkey:rod';
                };
            }
        };
        self::assertSame('passkey:rod', RequestPrincipal::of(self::with($optional)), 'the control: an optional parameter is no required argument — the method is called and the actor read');
    }

    private static function with(mixed $context): ServerRequest
    {
        return (new ServerRequest('GET', '/desktop'))->withAttribute(RequestPrincipal::ATTRIBUTE, $context);
    }
}
