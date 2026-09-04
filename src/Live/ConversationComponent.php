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
 * The conversation itself as a milpa/live component — the thread that COMPOSES the message components
 * (greenhouse decisions/0191). Rod's question answered in code: the chat is a Milpa Component made of other
 * Milpa Components (user/agent/thinking/tool/task/system messages + the consent gate). The component owns the
 * container and its empty state; the messages are cloned into it from their own components' prototypes. A
 * plugin extends the conversation (a banner, a filter) by hooking its render events.
 */
final class ConversationComponent implements ComponentDefinitionInterface
{
    /** The conversation container: whether it holds any messages yet. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-conversation',
            contractVersion: '1',
            summary: 'The conversation thread: composes the message components.',
            designContract: '@milpa/design:components/milpa-conversation.contract.json',
            propsSchema: ['empty' => ['type' => 'bool', 'default' => true]],
            stateSchema: ['empty' => ['type' => 'bool']],
            actions: [],
        );
    }

    /** Mount: a fresh conversation is empty until a message lands. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, 'desktop-conversation', '1', ['empty' => (bool) ($props['empty'] ?? true)], []);
    }

    /** The conversation is a container — no interaction of its own. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
