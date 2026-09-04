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
 * A system notice in the conversation as a milpa/live component (greenhouse decisions/0191): the house
 * speaking, not a participant — centred and quiet. The conversation clones its prototype and fills the text; a
 * plugin extends it by hooking {@see MessagePrototypes}' render events.
 */
final class SystemNoticeComponent implements ComponentDefinitionInterface
{
    /** A system notice: just its text. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-system-notice',
            contractVersion: '1',
            summary: 'A conversation message: the house speaking (a notice), not a participant.',
            designContract: '@milpa/design:components/milpa-system-notice.contract.json',
            propsSchema: ['text' => ['type' => 'string', 'default' => '']],
            stateSchema: ['text' => ['type' => 'string']],
            actions: [],
        );
    }

    /** Mount from props: the text in state. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, 'desktop-system-notice', '1', ['text' => (string) ($props['text'] ?? '')], []);
    }

    /** A system notice is inert — no interaction. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
