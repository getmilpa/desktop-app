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

/**
 * Renders the chips of live screens the agent declared (greenhouse decisions/0197): pick one to preview the UI
 * it is building, served by the live wire with no code deploy. Each chip carries the path it is served at, so
 * the Preview iframe points straight at it. Pure, so it is tested directly with fixtures.
 */
final class ScreenPreviewView
{
    /**
     * The declared-screen chips as HTML — one per screen, or an empty state.
     *
     * @param list<array{name: string, type: string, served_at: string}> $screens
     */
    public function html(array $screens): string
    {
        if ($screens === []) {
            return '<p class="mui-empty" style="color:var(--text-muted)">'
                . 'No screens declared yet. The agent declares one with <code>screen:declare</code> — it is served '
                . 'live at <code>/live/page?component=&lt;name&gt;</code> with no code deploy, and shows up here to preview.</p>';
        }

        $chips = '';
        foreach ($screens as $s) {
            $chips .= '<button type="button" class="screen-chip" data-screen-name="' . $this->esc($s['name']) . '" data-screen-src="' . $this->esc($s['served_at']) . '">'
                . '<span class="screen-chip__name">' . $this->esc($s['name']) . '</span>'
                . '<span class="screen-chip__type">' . $this->esc($s['type']) . '</span>'
                . '</button>';
        }

        return '<div class="screen-chips">' . $chips . '</div>';
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }
}
