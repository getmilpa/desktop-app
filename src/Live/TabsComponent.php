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
 * The Desktop shell's tablist (Conversation / Work / Activity / Context) as a milpa/live component — the third
 * surface of the "shell is pure Milpa Components" migration (greenhouse decisions/0189).
 *
 * The active tab is the shared signal `desktop.tab` (one truth): the tablist highlights it, the panes below
 * read it to show/hide, and the composer dock reads it to appear only on Conversation. Selecting a tab on the
 * server declares a `StateEffect` for it; the client sets it directly for a zero-latency switch — no imperative
 * DOM wiring.
 */
final class TabsComponent implements ComponentDefinitionInterface
{
    public const string TAB_SIGNAL = 'desktop.tab';

    /** The component contract: the tab list and active-tab props, an active-tab state, and a `select` action. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-tabs',
            contractVersion: '1',
            summary: "The Desktop shell's main tablist: Conversation, Work, Activity, Context.",
            designContract: '@milpa/design:components/milpa-tabs.contract.json',
            propsSchema: [
                'tabs' => ['type' => 'array', 'default' => []],
                'activeTab' => ['type' => 'string', 'default' => 'chat'],
            ],
            stateSchema: ['activeTab' => ['type' => 'string']],
            actions: ['select' => ['payload' => ['tab' => 'string']]],
        );
    }

    /** Mount from props: the active tab in state; the tab list in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-tabs',
            '1',
            ['activeTab' => (string) ($props['activeTab'] ?? 'chat')],
            ['tabs' => \is_array($props['tabs'] ?? null) ? $props['tabs'] : []],
        );
    }

    /** Select a tab: update the active-tab state and declare the shared `desktop.tab` signal. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $tab = (string) ($request->payload['tab'] ?? 'chat');
        $state = $request->state;

        return new InteractionResult(
            state: new StateSnapshot($state->componentId, $state->componentName, $state->version, array_merge($state->data, ['activeTab' => $tab]), $state->meta),
            // Declare the shared tab signal: the tablist, the panes and the composer dock all read `desktop.tab`.
            effects: [['type' => 'state', 'key' => self::TAB_SIGNAL, 'value' => $tab]],
        );
    }
}
