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
 * The Desktop shell's sidebar as a milpa/live component — the first surface of the "shell is pure Milpa
 * Components" migration (greenhouse decisions/0189). Not hand-written HTML: a declared component with a
 * contract, a signed state envelope, lifecycle events and a signal-driven active nav.
 *
 * The active nav is the shared signal `desktop.nav` (one truth), so the sidebar highlight and the main view
 * read the same value. Selecting a nav on the server declares a `StateEffect` for it; the client sets it
 * directly for a zero-latency highlight.
 *
 * @phpstan-type SidebarSession array{id: string, goal: string, state: string}
 */
final class SidebarComponent implements ComponentDefinitionInterface
{
    public const string NAV_SIGNAL = 'desktop.nav';

    /** The component contract: brand/nav/sessions props, an active-nav state, and a `select` action. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-sidebar',
            contractVersion: '1',
            summary: "The Desktop shell's sidebar: brand, navigation, session list and actions.",
            designContract: '@milpa/design:components/milpa-sidebar.contract.json',
            propsSchema: [
                'sessions' => ['type' => 'array', 'default' => []],
                'activeSession' => ['type' => 'string', 'required' => false],
                'activeNav' => ['type' => 'string', 'default' => 'sessions'],
                'decisions' => ['type' => 'int', 'default' => 0],
            ],
            stateSchema: ['activeNav' => ['type' => 'string']],
            actions: ['select' => ['payload' => ['nav' => 'string']]],
        );
    }

    /** Mount from props: the active nav in state; the sessions, active session and decisions count in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-sidebar',
            '1',
            ['activeNav' => (string) ($props['activeNav'] ?? 'sessions')],
            [
                'sessions' => \is_array($props['sessions'] ?? null) ? $props['sessions'] : [],
                'activeSession' => (string) ($props['activeSession'] ?? ''),
                'decisions' => (int) ($props['decisions'] ?? 0),
            ],
        );
    }

    /** Select a nav: update the active-nav state and declare the shared `desktop.nav` signal. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $nav = (string) ($request->payload['nav'] ?? 'sessions');
        $state = $request->state;

        return new InteractionResult(
            state: new StateSnapshot($state->componentId, $state->componentName, $state->version, array_merge($state->data, ['activeNav' => $nav]), $state->meta),
            // Declare the shared nav signal: the sidebar highlight and the main view both read `desktop.nav`.
            effects: [['type' => 'state', 'key' => self::NAV_SIGNAL, 'value' => $nav]],
        );
    }
}
