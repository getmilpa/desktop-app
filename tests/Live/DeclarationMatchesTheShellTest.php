<?php

/**
 * This file is part of Milpa Desktop App — the local agent workspace of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/desktop-app
 */

declare(strict_types=1);

namespace Milpa\DesktopApp\Tests\Live;

use Milpa\DesktopApp\Admin\AgentViewComponent;
use Milpa\DesktopApp\DesktopAppPlugin;
use Milpa\DesktopApp\Live\ComposerMessageComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The declaration and the shell are two lists, so they are measured against each other.
 *
 * `DesktopAppPlugin::COMPONENTS` tells the catalogue what this plugin brings; `ShellController`
 * paints them. Two hand-kept lists of the same fact is a lie waiting to happen — and the lie would
 * be the exact defect greenhouse decisions/0213 names: a catalogue reporting a capability that is
 * not wired. So every surface the shell declares must appear in the declaration.
 *
 * They are NOT identical, and the two exceptions are asserted by name so they stay decisions rather
 * than drift: {@see ComposerMessageComponent} enters through `DesktopComponents` instead, registered
 * under `ComposerField::COMPONENT` — the name `textarea`, which is why the catalogue reports that
 * name as declared by two hosts; and {@see AgentViewComponent} is built inside `AgentView::of()` for
 * the admin's guest section and never reaches a registry at all.
 */
#[CoversClass(DesktopAppPlugin::class)]
final class DeclarationMatchesTheShellTest extends TestCase
{
    public function testEverySurfaceTheShellPaintsIsDeclared(): void
    {
        $painted = $this->componentsTheShellDeclares();
        self::assertNotSame([], $painted, 'the shell declares no surfaces — this test would pass vacuously');

        $declared = [];
        foreach (DesktopAppPlugin::COMPONENTS as $class) {
            $declared[] = $class::contract()->name;
        }

        foreach ($painted as $name) {
            self::assertContains($name, $declared, $name . ' is painted by the shell but declared to nobody');
        }
    }

    public function testEveryDeclaredClassIsAComponentDefinition(): void
    {
        foreach (DesktopAppPlugin::COMPONENTS as $class) {
            self::assertTrue(class_exists($class), $class . ' is declared but does not exist');
            self::assertTrue(is_subclass_of($class, ComponentDefinitionInterface::class), $class . ' is not a component');
        }
    }

    public function testTheTwoComponentsOutsideTheShellAreTheComposerFieldAndTheGuestView(): void
    {
        $painted = $this->componentsTheShellDeclares();

        $unpainted = [];
        foreach (DesktopAppPlugin::COMPONENTS as $class) {
            $name = $class::contract()->name;
            if (!\in_array($name, $painted, true)) {
                $unpainted[] = $class;
            }
        }

        self::assertSame([ComposerMessageComponent::class, AgentViewComponent::class], $unpainted);
    }

    /**
     * The component names `ShellController::declareSurfaces()` registers, read from the source.
     *
     * Reading the source rather than booting the shell is deliberate: booting it needs a request,
     * a container and a session, and what this test asks about is the LIST, not the paint.
     *
     * @return list<string>
     */
    private function componentsTheShellDeclares(): array
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/Controllers/ShellController.php');
        self::assertIsString($source);

        preg_match_all('/declare\(new (\w+)\(/', $source, $matches);

        $names = [];
        foreach ($matches[1] as $short) {
            $class = 'Milpa\\DesktopApp\\Live\\' . $short;
            if (class_exists($class) && is_subclass_of($class, ComponentDefinitionInterface::class)) {
                $names[] = $class::contract()->name;
            }
        }

        return array_values(array_unique($names));
    }
}
