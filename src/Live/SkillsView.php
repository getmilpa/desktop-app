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
 * Renders the agent's skills — each one's name, description, and WHO may invoke it (greenhouse decisions/0197).
 *
 * A skill guides judgment; it is not a tool that runs. The human sees the same list the agent reaches for
 * ({@see \Milpa\DesktopApp\Data\DesktopData::skills()}). Pure, so it is tested directly with fixtures.
 */
final class SkillsView
{
    /**
     * The skills as HTML — one card each, or an empty state.
     *
     * @param list<array{name: string, description: string, model_invocable: bool, user_invocable: bool}> $skills
     */
    public function html(array $skills): string
    {
        if ($skills === []) {
            return '<p class="mui-empty" style="color:var(--text-muted)">'
                . 'No skills yet. A skill is a <code>skills/&lt;name&gt;/SKILL.md</code> the agent (or you) can reach for — it guides judgment, it is not a tool that runs.</p>';
        }

        $cards = '';
        foreach ($skills as $s) {
            $who = $this->who($s['model_invocable'], $s['user_invocable']);
            $desc = $this->esc($s['description']);
            $cards .= '<li class="skill-card">'
                . '<div class="skill-card__head"><span class="skill-card__name">' . $this->esc($s['name']) . '</span>' . $who . '</div>'
                . ($desc !== '' ? '<p class="skill-card__desc">' . $desc . '</p>' : '')
                . '</li>';
        }

        return '<ol class="skill-list">' . $cards . '</ol>';
    }

    private function who(bool $model, bool $user): string
    {
        if ($model && $user) {
            return '<span class="mui-badge mui-badge--success">agent &amp; you</span>';
        }
        if ($model) {
            return '<span class="mui-badge">agent</span>';
        }
        if ($user) {
            return '<span class="mui-badge">you</span>';
        }

        return '<span class="mui-badge mui-badge--warning">neither</span>';
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }
}
