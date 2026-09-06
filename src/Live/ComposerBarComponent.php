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
 * The composer BAR as a milpa/live component (greenhouse decisions/0211, phase C2).
 *
 * The last surface the shell still hand-stitched. It is a container: the text field inside it is its own
 * component ({@see ComposerMessageComponent}, a `<milpa:textarea>`), and the completion popup is a pure
 * view ({@see CommandListView}) — this component owns the bar around them, and its module
 * (`desktop-composer.js`) owns everything that bar DOES: the draft signal, the token count, the send, the
 * mode chip and its menu.
 *
 * Its state is the permission mode the chip shows, so a re-render over `POST /desktop/live` paints the bar
 * with the mode the session is actually in. It declares no action of its own: the mode is written through
 * the settings door (a partial post that merges), the way every other Desktop setting is.
 */
final class ComposerBarComponent implements ComponentDefinitionInterface
{
    /** The composer bar: which permission mode the chip is showing. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-composer',
            contractVersion: '1',
            summary: 'The composer bar: the field, its chips, the mode and the completion popup.',
            designContract: '@milpa/design:components/milpa-composer.contract.json',
            propsSchema: ['mode' => ['type' => 'string', 'default' => 'ask']],
            stateSchema: ['mode' => ['type' => 'string']],
            actions: [],
        );
    }

    /** Mount: the mode the server saved, or the mode that ASKS. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $mode = \is_string($props['mode'] ?? null) && $props['mode'] !== '' ? (string) $props['mode'] : 'ask';

        return new StateSnapshot($context->componentId, 'desktop-composer', '1', ['mode' => $mode], []);
    }

    /** The bar is a container — its field, its chips and its menu each act through their own seam. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
