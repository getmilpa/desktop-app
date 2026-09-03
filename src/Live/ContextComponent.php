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

namespace Milpa\DesktopApp\Live;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Desktop shell's Context tab (the grid of panels other plugins contribute) as a milpa/live component —
 * the sixth surface of the "shell is pure Milpa Components" migration (greenhouse decisions/0189).
 *
 * The Context tab is a PROJECTION surface AND the shell's own extension point: plugins contribute panels
 * through `desktop.shell.compose` (addPanel), driven live via `MilpaShell.panel()`. Making it a component does
 * not change how plugins contribute — it makes the container itself declared, signed, and observable through
 * its own render events.
 *
 * @phpstan-type Panel array{id: string, title: string|null, html: string}
 */
final class ContextComponent implements ComponentDefinitionInterface
{
    /** The component contract: the contributed panels prop, a read-only state, no live actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-context',
            contractVersion: '1',
            summary: "The Desktop shell's Context tab: the grid of panels other plugins contribute.",
            designContract: '@milpa/design:components/milpa-context.contract.json',
            propsSchema: [
                'panels' => ['type' => 'array', 'default' => []],
            ],
            stateSchema: ['count' => ['type' => 'int']],
            actions: [],
        );
    }

    /** Mount from props: the panel count in state; the contributed panels in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $panels = \is_array($props['panels'] ?? null) ? $props['panels'] : [];

        return new StateSnapshot(
            $context->componentId,
            'desktop-context',
            '1',
            ['count' => \count($panels)],
            ['panels' => $panels],
        );
    }

    /** The Context tab is a projection surface: plugins contribute panels live, so this is a no-op echo. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
