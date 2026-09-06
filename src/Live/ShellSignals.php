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

use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\I18n\Catalog;

/**
 * The shared signals the Desktop's surfaces read, and the derivations built on them — ONE authority for
 * both pages that mount those surfaces (greenhouse decisions/0211, slice 3).
 *
 * Until the Desktop was only ever its own page, this lived inside {@see \Milpa\DesktopApp\Controllers\ShellController}
 * as the JSON of `#milpa-live-signals`. The Agent region of milpa/admin mounts the SAME components inside
 * SOMEBODY ELSE's document, and that document seeds its own tags: two copies of this map would be two
 * truths about what a fresh page starts with, and the first one to drift would be a surface reading a
 * signal nobody seeded — a chip with no label, a send button that never enables.
 *
 * So the map is here, said once, and both hosts ask for it: the shell serializes it into its own tag, and
 * the admin merges it into the page's ({@see \Milpa\Admin\View\LiveSeeds}), where a key two declarers give
 * different values is an error that names both.
 *
 * Every value the UI SHOWS is a signal, and every signal is seeded from the server: the mode from the
 * saved setting, the counters from the session, the words from the catalog. A value shown that is not a
 * signal is a value that goes stale (greenhouse decisions/0191).
 */
final class ShellSignals
{
    /**
     * The page's initial shared signals, from the catalog's words and the session's real figures.
     *
     * @param Catalog          $catalog the declared locale's copy — every WORD here is the catalog's
     * @param DesktopData|null $data    the session seam; absent, the map is a settled, empty session
     *
     * @return array<string, mixed>
     */
    public static function of(Catalog $catalog, ?DesktopData $data = null): array
    {
        $settings = $data?->settings() ?? [];
        $modeKey = \is_string($settings['mode'] ?? null) && isset(ComposerBar::MODE_KEYS[$settings['mode']]) ? (string) $settings['mode'] : 'ask';
        $counters = $data?->counters();
        $ctx = $data?->context() ?? ['tokens' => 0, 'window' => 32768];
        // The session's state as a WORD is copy, so it is the catalog's — the same key the turn's module
        // writes when it starts and ends a turn. Seeded and written from one place, in one language.
        $state = strtolower(\is_array($counters) ? (string) $counters['state'] : 'idle');
        $running = \in_array($state, ['working', 'thinking', 'running', 'busy'], true);

        return [
            // The mode is a signal PAIR (greenhouse decisions/0202): the VALUE every turn sends to the
            // agent (ask | acknowledge | auto) and its label for the chip. Seeded from the SAVED setting
            // on every load — the server's copy is the one truth; nothing about the mode is remembered
            // in the browser.
            'composer.mode' => $modeKey,
            'composer.mode.label' => ComposerBar::modeLabel($catalog, $modeKey),
            // Every counter the UI shows is a SIGNAL — one truth, projected to the composer chips, the
            // status bar and the panels alike (greenhouse decisions/0191, Rod). The live feed and the
            // turn update these; every place that reads them updates at once.
            'session.state.label' => $catalog->tr($running ? 'session.state.working' : 'session.state.idle'),
            'session.turns' => \is_array($counters) ? (int) $counters['turns'] : 0,
            'session.steps' => \is_array($counters) ? (int) $counters['steps'] : 0,
            'session.tokens' => \is_array($counters) ? (int) $counters['tokens'] : 0,
            'session.tool_calls' => \is_array($counters) ? (int) $counters['tool_calls'] : 0,
            'context.used' => self::kfmt((int) $ctx['tokens']),
            'context.window' => self::kfmt((int) $ctx['window']),
            'desktop.nav' => 'sessions',
            'desktop.tab' => 'chat',
            'desktop.gate.open' => false,
            // The couplings phase A dissolved into signals (greenhouse decisions/0211):
            //  · `session.working` replaces setWorking() poking the send button and the topbar badge — both
            //    BIND to it now, so anything else that must follow the turn binds too instead of being poked;
            //  · `composer.draft` is the other half of the send button's state (is there anything to send),
            //    so its `disabled` is a binding and not an assignment;
            //  · `ui.dismiss` is bumped by the ONE document-level click listener the guard module owns — the
            //    mode menu and the command popup consume it instead of each hanging its own listener;
            //  · `desktop.notice` is what the guard SAYS when a door answers, instead of reaching into the
            //    conversation: whoever renders notices consumes it.
            'session.working' => $state === 'working',
            'composer.draft' => false,
            'ui.dismiss' => 0,
            'desktop.notice' => null,
            // What phase B made signals (greenhouse decisions/0211):
            //  · `composer.panel` is WHICH floating panel is open — the chips set it, both panels bind
            //    `:hidden` to it, and typing clears it, so nothing pokes a `.hidden` property;
            //  · `ui.theme` is the shell's theme in three places at once (the document, the chrome toggle,
            //    the Settings buttons) — seeded 'system', corrected by the topbar module from what was
            //    remembered, and never a second copy for the buttons to disagree with;
            //  · `desktop.auth.open` is the entry overlay's visibility, so any surface can ask for it;
            //  · `settings.saved` is what the save badge SHOWS — `{ok, text}` while a save is being
            //    reported, null once it has been. Only the door's answer ever fills it.
            'composer.panel' => '',
            'ui.theme' => 'system',
            'desktop.auth.open' => false,
            'settings.saved' => null,
            // What phase D made signals (greenhouse decisions/0211): the TRANSPORT's state. The status bar
            // used to be poked by id from the page's inline script; `MilpaShell.status()` writes these two
            // and the bar BINDS them, so a second surface that wants to show the connection binds too.
            'conn.state' => 'connecting',
            'conn.label' => $catalog->tr('conn.connecting'),
        ];
    }

    /**
     * The derived signals — a template over other signals, evaluated by the runtime, so a figure that
     * changes anywhere changes everywhere it is written.
     *
     * @return array<string, array{template: string}>
     */
    public static function computed(): array
    {
        return [
            'session.summary' => ['template' => '{session.state.label} · {session.turns} turns'],
            'session.counters' => ['template' => '{session.turns} turns · {session.tool_calls} tools'],
            'context.usage' => ['template' => '{context.used}/{context.window}'],
            'session.status' => ['template' => '{session.turns} turns · {session.steps} steps · {session.tokens} tokens · {session.tool_calls} tool calls'],
        ];
    }

    /** Format a token count as "9.25K". */
    private static function kfmt(int $n): string
    {
        return number_format($n / 1000, 2) . 'K';
    }
}
