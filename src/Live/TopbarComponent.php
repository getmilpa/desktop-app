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
 * The Desktop shell's topbar as a milpa/live component — the second surface of the "shell is pure Milpa
 * Components" migration (greenhouse decisions/0189). It shows the current session's immutable goal, its id and
 * mode, and — on the right — the live session state, the mode and Export session.
 *
 * The topbar is a PROJECTION surface: it owns no interactive state of its own, it reads shared signals
 * (`session.state.label`, `session.summary`, `composer.mode.label`) so the same truth shows here and in the
 * composer's chip. Selecting the mode elsewhere re-paints this topbar with no wiring.
 *
 * @phpstan-type TopbarSession array{id: string, goal: string}
 */
final class TopbarComponent implements ComponentDefinitionInterface
{
    /** The component contract: session goal/id/mode/state props, a read-only state, no actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-topbar',
            contractVersion: '1',
            summary: "The Desktop shell's topbar: session goal and id, and the live state/mode/export actions.",
            designContract: '@milpa/design:components/milpa-topbar.contract.json',
            propsSchema: [
                'goal' => ['type' => 'string', 'default' => ''],
                'sessionId' => ['type' => 'string', 'default' => ''],
                'mode' => ['type' => 'string', 'default' => 'Ask before changing'],
                'state' => ['type' => 'string', 'default' => 'idle'],
                'hasSession' => ['type' => 'bool', 'default' => false],
            ],
            stateSchema: ['state' => ['type' => 'string'], 'mode' => ['type' => 'string']],
            actions: [],
        );
    }

    /** Mount from props: the live state and mode in state; the goal, id and session flag in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-topbar',
            '1',
            [
                'state' => (string) ($props['state'] ?? 'idle'),
                'mode' => (string) ($props['mode'] ?? 'Ask before changing'),
            ],
            [
                'goal' => (string) ($props['goal'] ?? ''),
                'sessionId' => (string) ($props['sessionId'] ?? ''),
                'hasSession' => (bool) ($props['hasSession'] ?? false),
            ],
        );
    }

    /** The topbar is a projection surface: it has no interactive action, so an interaction is a no-op echo. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
