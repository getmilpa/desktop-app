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
 * A turn's result as a CLAIM the house judged, as a milpa/live component (greenhouse decisions/0191) — the
 * conversation face of the closure verdict the `agent` operation computes (evidence/0442, the double coin):
 * the answer is a claim, and the ledger either backs it (verified) or it does not (disputed). The conversation
 * clones this component and fills its verdict + reasons; a plugin extends it by hooking its render events.
 */
final class ResultClaimComponent implements ComponentDefinitionInterface
{
    /** A result claim: whether the ledger verified it, and why not when it did not. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-result-claim',
            contractVersion: '1',
            summary: "A turn's result as a claim the house judged: verified, or disputed with reasons.",
            designContract: '@milpa/design:components/milpa-result-claim.contract.json',
            propsSchema: [
                'verified' => ['type' => 'bool', 'default' => true],
                'reasons' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: ['verified' => ['type' => 'bool']],
            actions: [],
        );
    }

    /** Mount from props: the verdict in state, the reasons in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-result-claim',
            '1',
            ['verified' => (bool) ($props['verified'] ?? true)],
            ['reasons' => (string) ($props['reasons'] ?? '')],
        );
    }

    /** A result claim is a projection of the ledger's verdict — inert in the conversation. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
