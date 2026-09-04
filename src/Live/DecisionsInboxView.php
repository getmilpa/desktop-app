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
 * Renders the decisions inbox — the questions agents parked, across all sessions (greenhouse decisions/0195).
 *
 * The live gate lives in the conversation of its own session; this is the cross-session backlog, so a human
 * sees every durable question waiting for them wherever it was raised. Each card links to its session. Pure,
 * so it is tested directly with fixtures.
 */
final class DecisionsInboxView
{
    /**
     * The inbox as HTML: one card per parked question, or an empty state.
     *
     * @param list<array{session: string, goal: string, question: string, operation: string, reason: string}> $pending
     */
    public function html(array $pending): string
    {
        if ($pending === []) {
            return '<p class="mui-empty" id="milpa-decisions-empty" style="color:var(--text-muted)">'
                . 'No decisions to make. When an agent parks a gate, it appears here for you to approve or refuse.</p>';
        }

        $cards = '';
        foreach ($pending as $d) {
            $goal = $this->esc($d['goal']);
            $facts = [];
            if ($d['operation'] !== '') {
                $facts[] = 'operation <strong>' . $this->esc($d['operation']) . '</strong>';
            }
            if ($d['reason'] !== '') {
                $facts[] = 'reason ' . $this->esc($d['reason']);
            }
            $factLine = $facts === [] ? '' : '<p class="decision-card__facts">' . implode(' · ', $facts) . '</p>';

            $cards .= '<li class="decision-card" data-decision-session="' . $this->esc($d['session']) . '">'
                . ($goal !== '' ? '<p class="decision-card__goal">' . $goal . '</p>' : '')
                . '<p class="decision-card__q">' . $this->esc($d['question']) . '</p>'
                . $factLine
                . '<a class="mui-btn mui-btn--sm decision-card__open" href="/desktop?session=' . rawurlencode($d['session']) . '">Open session</a>'
                . '</li>';
        }

        return '<ol class="mui-replay__stream" id="milpa-decisions-list" aria-live="polite">' . $cards . '</ol>';
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }
}
