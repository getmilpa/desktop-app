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
 * The Desktop shell's consent gate as a milpa/live component — the seventh and last surface of the "shell is
 * pure Milpa Components" migration (greenhouse decisions/0189).
 *
 * The gate is the durable question, not a modal: when an agent parks its turn, the gate opens with the
 * operation it is asking to run, and the human answers with a passkey in this origin. Its VISIBILITY is the
 * shared signal `desktop.gate.open` (one truth): the live `gate.opened` event fills its dynamic content and
 * sets the signal true; the `dismiss` action declares the signal false. The passkey answer itself is unchanged
 * (the `/webauthn/intent` link) — this migration makes the gate declared, signed and observable.
 */
final class GateComponent implements ComponentDefinitionInterface
{
    public const string OPEN_SIGNAL = 'desktop.gate.open';

    /** The component contract: the parked operation props, an open state, and a `dismiss` action. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-gate',
            contractVersion: '1',
            summary: "The Desktop shell's consent gate: an agent's durable question, answered with a passkey.",
            designContract: '@milpa/design:components/milpa-gate.contract.json',
            propsSchema: [
                'open' => ['type' => 'bool', 'default' => false],
                'operation' => ['type' => 'string', 'default' => ''],
                'arguments' => ['type' => 'string', 'default' => ''],
                'action' => ['type' => 'string', 'default' => 'An agent is asking to act.'],
            ],
            stateSchema: ['open' => ['type' => 'bool']],
            actions: ['dismiss' => ['payload' => []]],
        );
    }

    /** Mount from props: the open flag in state; the parked operation, arguments and action in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-gate',
            '1',
            ['open' => (bool) ($props['open'] ?? false)],
            [
                'operation' => (string) ($props['operation'] ?? ''),
                'arguments' => (string) ($props['arguments'] ?? ''),
                'action' => (string) ($props['action'] ?? 'An agent is asking to act.'),
            ],
        );
    }

    /** Dismiss the gate: close the open state and declare the shared `desktop.gate.open` signal false. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $state = $request->state;

        return new InteractionResult(
            state: new StateSnapshot($state->componentId, $state->componentName, $state->version, array_merge($state->data, ['open' => false]), $state->meta),
            // Declare the shared visibility signal: the gate card binds its :hidden to `desktop.gate.open`.
            effects: [['type' => 'state', 'key' => self::OPEN_SIGNAL, 'value' => false]],
        );
    }
}
