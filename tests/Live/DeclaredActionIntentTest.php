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

use Milpa\Command\Effect\Mutation;
use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\Live\SettingsScreenComponent;
use Milpa\Live\ValueObjects\ActionContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The name of an action is not evidence about what it does.
 *
 * `desktop-settings::save` does not save: the write is `POST /desktop/settings` and the handler only
 * records what the door answered. Until an action could declare its intent, the NAME was the only signal a
 * reader had — and here the name says the opposite of the truth. This is the case the declaration exists
 * for, so it is the one pinned by a test (greenhouse decisions/0214 point 2).
 */
#[CoversClass(SettingsScreenComponent::class)]
final class DeclaredActionIntentTest extends TestCase
{
    public function testTheActionCalledSaveDeclaresThatItDoesNotSave(): void
    {
        $action = SettingsScreenComponent::contract()->action('save');

        self::assertInstanceOf(ActionContract::class, $action);
        self::assertTrue($action->declaresEffects(), 'silence would leave the misleading name as the only signal');
        self::assertFalse($action->mutating);
        self::assertSame(Mutation::None, $action->effects?->mutation);
        self::assertStringContainsString('POST /desktop/settings', $action->summary, 'and it names who does write');
    }

    public function testTheDeclarationKeepsThePayloadTheBareFormCarried(): void
    {
        // Migrating a shape must not drop what the old one said, or the richer form is a downgrade.
        self::assertSame(
            ['endpoint' => 'string', 'mode' => 'string'],
            SettingsScreenComponent::contract()->action('save')?->payload,
        );
    }

    public function testEveryUndeclaredActionStillReadsAsUndeclaredRatherThanHarmless(): void
    {
        // The additive promise: the 41 actions that did not migrate say nothing, and nothing is answered
        // on their behalf. If this ever reads `true`, silence has started meaning «safe».
        $undeclared = 0;
        foreach (DesktopAppPlugin::COMPONENTS as $class) {
            foreach (array_keys($class::contract()->actions) as $name) {
                $action = $class::contract()->action((string) $name);
                if ($action === null || $action->declaresEffects()) {
                    continue;
                }

                ++$undeclared;
                self::assertFalse($action->mutating, $class . '::' . $name . ' answers on nobody\'s behalf');
                self::assertSame('', $action->summary);
            }
        }

        self::assertGreaterThan(0, $undeclared, 'this guard would pass vacuously if everything had migrated');
    }
}
