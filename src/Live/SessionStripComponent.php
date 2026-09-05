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
 * The session strip of embed mode as a milpa/live component (greenhouse decisions/0210, on the "shell is pure
 * Milpa Components" rule of decisions/0189): the sidebar's reach — the current session and every other one —
 * in one row above the conversation, for when the sidebar is folded.
 *
 * Its props are the same data the sidebar mounts with (`sessions`, `activeSession`); its state is the active
 * session. It declares no action: picking a session is a navigation (`?session=<id>&embed=1`, the way a
 * sidebar item is a link) and «New session» opens the shell's own overlay — both wired by the shell script to
 * the SAME handlers the sidebar uses, never to a second one.
 */
final class SessionStripComponent implements ComponentDefinitionInterface
{
    public const string NAME = 'desktop-session-strip';
    public const string VERSION = '1';

    /** The contract: the session list and the active session as props; the active session as state; no actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: self::VERSION,
            summary: "The Desktop shell's session strip in embed mode: the current session, a picker of every session, and «New session».",
            propsSchema: [
                'sessions' => ['type' => 'array', 'default' => []],
                'activeSession' => ['type' => 'string', 'required' => false],
            ],
            stateSchema: ['activeSession' => ['type' => 'string']],
        );
    }

    /** Mount from props: the active session in state; the session list in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            self::NAME,
            self::VERSION,
            ['activeSession' => (string) ($props['activeSession'] ?? '')],
            ['sessions' => \is_array($props['sessions'] ?? null) ? $props['sessions'] : []],
        );
    }

    /** No action is declared: a pick navigates and «New session» opens the shell's overlay; the state is returned as is. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf('«%s» declares no actions: a pick navigates to the session and «New session» opens the shell\'s overlay.', self::NAME)],
        );
    }
}
