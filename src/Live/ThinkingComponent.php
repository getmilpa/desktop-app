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
 * A message in the conversation: the agent's THINKING, as a milpa/live component — the first message type of
 * the "the conversation is pure Milpa Components too" arc (greenhouse decisions/0191). Until now the chat was
 * imperative DOM (`appendMessage`/`appendReasoning` with `createElement`); each message KIND is now a declared
 * component with a contract, lifecycle events, and its own behaviour — so a plugin (or the agent) can extend
 * or replace any message type without touching the conversation.
 *
 * The thinking block's behaviour is the component's own, declared in its markup + CSS (not per-instance JS):
 * the `data-open` attribute drives the collapse (`[data-open="0"]` hides the body), it is open while the model
 * reasons, its body fills with the reasoning as it streams, and when the turn ends it COLLAPSES to a toggle
 * ("thought for Ns") that re-opens on click via one delegated handler. The live stream feeds the instance the
 * words (into `[data-thinking-body]`) and the elapsed (into `[data-thinking-head]`).
 */
final class ThinkingComponent implements ComponentDefinitionInterface
{
    /** The component contract: an open/done state and a `toggle` action; no props (fed live by events). */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-thinking',
            contractVersion: '1',
            summary: "A conversation message: the agent's thinking, streamed live then collapsed to a toggle.",
            designContract: '@milpa/design:components/milpa-thinking.contract.json',
            propsSchema: [],
            stateSchema: ['open' => ['type' => 'bool'], 'done' => ['type' => 'bool']],
            actions: ['toggle' => ['payload' => []]],
        );
    }

    /** Mount: open while reasoning, not yet done. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-thinking',
            '1',
            ['open' => true, 'done' => false],
            [],
        );
    }

    /** Toggle the block open/closed — the collapse a reader controls after the turn. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $state = $request->state;
        $open = (bool) ($state->data['open'] ?? true);

        return new InteractionResult(
            state: new StateSnapshot($state->componentId, $state->componentName, $state->version, array_merge($state->data, ['open' => !$open]), $state->meta),
        );
    }
}
