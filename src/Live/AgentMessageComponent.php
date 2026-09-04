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
 * The agent's message as a milpa/live component — a message type of the "the conversation is pure Milpa
 * Components too" arc (greenhouse decisions/0191), after {@see ThinkingComponent}.
 *
 * An agent message carries its own TOOLS at the foot of the answer (Rod's ask): copy the response, and
 * regenerate it. They are declared actions of the component — a plugin (or the agent) can add more tools to
 * EVERY agent message by hooking the render events, without touching the conversation. The tools act on the
 * message through one delegated handler on the conversation, so a cloned instance needs no per-instance wiring.
 */
final class AgentMessageComponent implements ComponentDefinitionInterface
{
    /** The component contract: the message text prop and the foot tools (`copy`, `regenerate`). */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-agent-message',
            contractVersion: '1',
            summary: "An agent message with its foot tools: copy the answer, regenerate it.",
            designContract: '@milpa/design:components/milpa-agent-message.contract.json',
            propsSchema: [
                'text' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: ['text' => ['type' => 'string']],
            actions: [
                'copy' => ['payload' => []],
                'regenerate' => ['payload' => []],
            ],
        );
    }

    /** Mount from props: the answer text in state. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-agent-message',
            '1',
            ['text' => (string) ($props['text'] ?? '')],
            [],
        );
    }

    /** The foot tools act on the client (copy to clipboard, re-run the turn); an interaction is a no-op echo. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
