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
 * The Desktop's entry overlay — «Open workspace» — as a milpa/live component (greenhouse decisions/0211,
 * phase B5).
 *
 * It was the last big block of raw HTML in the shell's template: an overlay that names the app, states
 * plainly that a system user is NOT a verified identity, and creates the session. Now it is a declared
 * view like every other surface — its markup in a renderer, its look in `desktop-auth.css`, its
 * behaviour in `desktop-auth.js` — so a plugin can extend it through its render events and the page
 * carries no view it does not compose.
 *
 * Its VISIBILITY is the shared signal `desktop.auth.open` (one truth): the sidebar's «New session» and
 * the embed strip's control both open it through the client module, and the overlay closes itself when
 * the door answers instead of the handler, so the notice is in view.
 *
 * Nothing runs on open: the ceremony creates a session (`POST /desktop/sessions`) and reloads. Authority
 * is the door's (greenhouse decisions/0209) — this overlay authenticates nobody and signs nothing.
 */
final class AuthOverlayComponent implements ComponentDefinitionInterface
{
    /** The shared signal the overlay's visibility is. */
    public const string OPEN_SIGNAL = 'desktop.auth.open';

    /**
     * The component contract: the app and provider labels as props, an open state, and NO actions.
     *
     * The empty action list is the decision, not an omission. Visibility is the shared
     * {@see OPEN_SIGNAL} — a client store value every surface writes directly — so nothing about this
     * overlay ever travels over `POST /desktop/live`. Declaring `open`/`dismiss` there would put them on
     * the shared endpoint's authorizer, and the endpoint would answer `{"ok":true,"data":{"open":true}}`
     * beside markup still painted CLOSED: the renderer repaints the overlay from the server's own props,
     * not from the request's state (greenhouse decisions/0211). An action whose answer contradicts its
     * own markup is worse than no action, and this one buys nothing the signal does not already do.
     */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-auth',
            contractVersion: '1',
            summary: "The Desktop's entry overlay: name the app, see what your identity is worth, open a session.",
            propsSchema: [
                'open' => ['type' => 'bool', 'default' => false],
                'app' => ['type' => 'string', 'default' => 'getmilpa/framework'],
                'provider' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: ['open' => ['type' => 'bool']],
            actions: [],
        );
    }

    /** Mount from props: the open flag in state; the app and the model provider's label in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-auth',
            '1',
            ['open' => (bool) ($props['open'] ?? false)],
            [
                'app' => (string) ($props['app'] ?? 'getmilpa/framework'),
                'provider' => (string) ($props['provider'] ?? ''),
            ],
        );
    }

    /**
     * Nothing to handle: the contract declares no action, so the endpoint's authorizer refuses every one
     * of them ({@see contract()}) and this is only ever reached by a host calling it directly. It echoes
     * the state it was given — the overlay's visibility lives in the client store, not on the wire.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
