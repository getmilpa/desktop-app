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
 * The Capabilities screen as a milpa/live component (greenhouse decisions/0211, phase D2).
 *
 * The catalogue the human reads is the catalogue the agent reads (greenhouse decisions/0193) — INSTALLED
 * and AVAILABLE, each available one with the exact command that installs it. Its state is the two counts,
 * so a re-render over `POST /desktop/live` paints what the runtime actually reports.
 *
 * It declares no action of its own: installing a capability is the house's own gated
 * `capabilities:enable` over HTTP, a two-step with a `Confirm-Token`, and `desktop-capabilities.js` is
 * the one place that flow is run.
 */
final class CapabilitiesScreenComponent implements ComponentDefinitionInterface
{
    /** The capability catalogue: how many are installed, how many are available. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-capabilities',
            contractVersion: '1',
            summary: 'The capability catalogue: installed and available, with the command that installs one.',
            designContract: '@milpa/design:components/milpa-capabilities.contract.json',
            propsSchema: [
                'installed' => ['type' => 'array', 'default' => []],
                'available' => ['type' => 'array', 'default' => []],
            ],
            stateSchema: [
                'installed' => ['type' => 'integer'],
                'available' => ['type' => 'integer'],
            ],
            actions: [],
        );
    }

    /** Mount from props: the two counts are the state, so a re-render says what the runtime reports. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-capabilities',
            '1',
            [
                'installed' => \count(\is_array($props['installed'] ?? null) ? $props['installed'] : []),
                'available' => \count(\is_array($props['available'] ?? null) ? $props['available'] : []),
            ],
            [],
        );
    }

    /** Inert here: the install is the house's own gated operation, run through its HTTP projection. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
