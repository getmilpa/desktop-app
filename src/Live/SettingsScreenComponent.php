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
use Milpa\Command\Effect\EffectProfile;
use Milpa\Live\ValueObjects\ActionContract;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Desktop's Settings screen as a milpa/live component (greenhouse decisions/0211, phase B7).
 *
 * The screen was raw HTML in the shell's template with its Save/Discard wiring in the page's inline
 * script; it is a declared view now — markup from a renderer, look in `desktop-settings.css`, behaviour
 * in `desktop-settings.js`.
 *
 * Two things it does NOT do, on purpose. It does not persist here: `POST /desktop/settings` is the
 * writer, and the answer decides — «Saved» is only ever said on a 2xx (greenhouse decisions/0209). And
 * it does not remember the theme: the theme is the shared `ui.theme` signal the topbar's module owns, so
 * the three buttons here set the SAME value the window chrome's toggle does.
 *
 * The `settings.saved` signal is what the badge shows: `{ok, text}` while a save is being reported,
 * `null` once it has been. A signal, not a DOM poke, so any other surface can report the same fact.
 */
final class SettingsScreenComponent implements ComponentDefinitionInterface
{
    /** The shared signal the save badge is: `{ok: bool, text: string}` or null. */
    public const string SAVED_SIGNAL = 'settings.saved';

    /** The component contract: the persisted endpoint and the badge's copy as props, a saved state. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-settings',
            contractVersion: '1',
            summary: "The Desktop's Settings screen: model and provider, default autonomy, context, appearance.",
            propsSchema: [
                'endpoint' => ['type' => 'string', 'default' => ''],
                'sessionsPath' => ['type' => 'string', 'default' => '.milpa/sessions/'],
                'savedLabel' => ['type' => 'string', 'default' => 'Saved'],
            ],
            stateSchema: ['saved' => ['type' => 'bool']],
            actions: ['save' => new ActionContract(
                // An action NAMED save that does not save: the write is `POST /desktop/settings` and this
                // only records what the door answered. Before an action could declare what it is FOR, the
                // NAME was the only signal a reader had — and here the name says the opposite of the truth.
                // That is the case this declaration exists for.
                summary: 'Record that the door reported a successful save. The write is POST /desktop/settings.',
                mutating: false,
                effects: EffectProfile::readOnly(),
                payload: ['endpoint' => 'string', 'mode' => 'string'],
            )],
        );
    }

    /** Mount from props: nothing saved yet in state; the endpoint and the copy in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-settings',
            '1',
            ['saved' => false],
            [
                'endpoint' => (string) ($props['endpoint'] ?? ''),
                'sessionsPath' => (string) ($props['sessionsPath'] ?? '.milpa/sessions/'),
                'savedLabel' => (string) ($props['savedLabel'] ?? 'Saved'),
            ],
        );
    }

    /**
     * Record that a save was reported. The WRITE is `POST /desktop/settings`, not this handler: the
     * component only projects what the door answered, so nothing here can say «Saved» on its own.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $state = $request->state;

        return new InteractionResult(
            state: new StateSnapshot($state->componentId, $state->componentName, $state->version, array_merge($state->data, ['saved' => true]), $state->meta),
            effects: [['type' => 'state', 'key' => self::SAVED_SIGNAL, 'value' => ['ok' => true, 'text' => (string) ($state->meta['savedLabel'] ?? 'Saved')]]],
        );
    }
}
