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
 * The Desktop shell's Activity tab (the session's live fact stream + a projection of its counters) as a
 * milpa/live component — the fifth surface of the "shell is pure Milpa Components" migration (greenhouse
 * decisions/0189).
 *
 * The Activity tab is a PROJECTION surface: it renders the session's facts (not a full audit log) and its
 * counters. New facts arrive LIVE over the hub and are prepended to the `#milpa-activity` stream by the shell's
 * event client — that live transport is unchanged; this migration makes the tab a declared, signed, extensible
 * component (plugins can decorate the stream or the projection via its render events).
 *
 * @phpstan-type AuditFact array{seq: int, type: string, data: string}
 */
final class ActivityComponent implements ComponentDefinitionInterface
{
    /** The component contract: the audit facts and projection props, a read-only state, no live actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-activity',
            contractVersion: '1',
            summary: "The Desktop shell's Activity tab: the session's live fact stream and a counter projection.",
            designContract: '@milpa/design:components/milpa-activity.contract.json',
            propsSchema: [
                'audit' => ['type' => 'array', 'default' => []],
                'projection' => ['type' => 'array', 'default' => []],
            ],
            stateSchema: ['facts' => ['type' => 'int']],
            actions: [],
        );
    }

    /** Mount from props: the fact count in state; the audit facts and projection stats in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $audit = \is_array($props['audit'] ?? null) ? $props['audit'] : [];

        return new StateSnapshot(
            $context->componentId,
            'desktop-activity',
            '1',
            ['facts' => \count($audit)],
            [
                'audit' => $audit,
                'projection' => \is_array($props['projection'] ?? null) ? $props['projection'] : [],
            ],
        );
    }

    /** The Activity tab is a projection surface: facts arrive live over the hub, so this is a no-op echo. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
