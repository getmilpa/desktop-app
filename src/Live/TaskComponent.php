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
 * A task the agent added to the plan, as a conversation message component (greenhouse decisions/0191): a
 * leading mark, the title, and a status badge. The conversation clones its prototype and fills the title and
 * status; a plugin extends it by hooking {@see MessagePrototypes}' render events.
 */
final class TaskComponent implements ComponentDefinitionInterface
{
    /** A task row: a title and a status. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-task',
            contractVersion: '1',
            summary: 'A conversation message: a task the agent added to the plan.',
            designContract: '@milpa/design:components/milpa-task.contract.json',
            propsSchema: [
                'title' => ['type' => 'string', 'default' => ''],
                'status' => ['type' => 'string', 'default' => 'todo'],
            ],
            stateSchema: ['status' => ['type' => 'string']],
            actions: [],
        );
    }

    /** Mount from props: the status in state, the title in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-task',
            '1',
            ['status' => (string) ($props['status'] ?? 'todo')],
            ['title' => (string) ($props['title'] ?? '')],
        );
    }

    /** A task message is inert here — the work board owns moving it (greenhouse decisions/0484). */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
