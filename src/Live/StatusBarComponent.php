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
 * The status bar as a milpa/live component (greenhouse decisions/0211, phase D4).
 *
 * A pure PROJECTION surface: the connection (`conn.state` / `conn.label`, said by the bus when the
 * transport changes), the model this Desktop drives, and the session's counters (`session.status`, the
 * computed signal the turn and the hub keep). It BINDS all three — nothing pokes it, and it is the reason
 * the connection stopped being `document.getElementById('milpa-conn').textContent = …` in an inline
 * script and became a signal.
 *
 * It therefore ships a stylesheet and NO module: everything it shows is somebody else's truth, projected.
 */
final class StatusBarComponent implements ComponentDefinitionInterface
{
    /** The status bar: which model the Desktop drives. Everything else it shows is a signal. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-statusbar',
            contractVersion: '1',
            summary: 'The status bar: the connection, the model, the session counters.',
            designContract: '@milpa/design:components/milpa-statusbar.contract.json',
            propsSchema: ['model' => ['type' => 'string', 'default' => '']],
            stateSchema: ['model' => ['type' => 'string']],
            actions: [],
        );
    }

    /** Mount from props: the model's name is the only thing the bar knows that is not a signal. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-statusbar',
            '1',
            ['model' => \is_string($props['model'] ?? null) ? (string) $props['model'] : ''],
            [],
        );
    }

    /** Inert: a projection acts on nothing. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
