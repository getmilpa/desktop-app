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
 * A tool call in the conversation as a milpa/live component (greenhouse decisions/0191): the machinery made
 * legible — the operation name and its result. The conversation clones its prototype and fills the name and
 * result regions; a plugin extends it by hooking {@see MessagePrototypes}' render events.
 */
final class ToolCallComponent implements ComponentDefinitionInterface
{
    /** A tool call: the operation name and a short result. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-tool-call',
            contractVersion: '1',
            summary: 'A conversation message: a tool the agent called and its result.',
            designContract: '@milpa/design:components/milpa-tool-call.contract.json',
            propsSchema: [
                'name' => ['type' => 'string', 'default' => 'tool'],
                'result' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: ['name' => ['type' => 'string']],
            actions: [],
        );
    }

    /** Mount from props: the tool name in state, the result in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-tool-call',
            '1',
            ['name' => (string) ($props['name'] ?? 'tool')],
            ['result' => (string) ($props['result'] ?? '')],
        );
    }

    /** A tool-call message is inert here — running the tool is the agent runtime's. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
