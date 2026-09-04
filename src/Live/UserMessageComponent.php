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
 * The user's message as a milpa/live component — a conversation message type of the "conversation is pure Milpa
 * Components" arc (greenhouse decisions/0191). The conversation clones its prototype and fills the text; a
 * plugin extends it by hooking {@see MessagePrototypes}' render events.
 */
final class UserMessageComponent implements ComponentDefinitionInterface
{
    /** The user's own message: just its text. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-user-message',
            contractVersion: '1',
            summary: 'A conversation message: what the human said.',
            designContract: '@milpa/design:components/milpa-user-message.contract.json',
            propsSchema: ['text' => ['type' => 'string', 'default' => '']],
            stateSchema: ['text' => ['type' => 'string']],
            actions: [],
        );
    }

    /** Mount from props: the text in state. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, 'desktop-user-message', '1', ['text' => (string) ($props['text'] ?? '')], []);
    }

    /** A user message is inert — no interaction. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
