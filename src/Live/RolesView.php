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
 * Renders the specialist agent roles the app declares (greenhouse decisions/0197): each a named authority with
 * the skills it preloads and the tools it is denied. A role names authority that already governs; the skills
 * only suggest. Pure, so it is tested directly with fixtures.
 */
final class RolesView
{
    /**
     * The roles as HTML — one card each, or an empty state.
     *
     * @param list<array{name: string, produces: string, deny: list<string>, skills: list<string>}> $roles
     */
    public function html(array $roles): string
    {
        if ($roles === []) {
            return '<p class="mui-empty" style="color:var(--text-muted)">'
                . 'No specialist roles yet. A role is a <code>.milpa/agents/&lt;name&gt;.md</code> composed with '
                . '<code>agent:role:declare</code> — a brief, the skills it preloads, and the tools it is denied.</p>';
        }

        $cards = '';
        foreach ($roles as $r) {
            $skills = $this->chips($r['skills'], 'mui-badge--success');
            $deny = $this->chips($r['deny'], 'mui-badge--warning');
            $produces = $r['produces'] !== '' ? '<p class="role-card__line"><span class="role-card__key">produces</span> ' . $this->esc($r['produces']) . '</p>' : '';
            $cards .= '<li class="role-card">'
                . '<p class="role-card__name">' . $this->esc($r['name']) . '</p>'
                . $produces
                . ($skills !== '' ? '<p class="role-card__line"><span class="role-card__key">preloads</span> ' . $skills . '</p>' : '')
                . ($deny !== '' ? '<p class="role-card__line"><span class="role-card__key">denied</span> ' . $deny . '</p>' : '')
                . '</li>';
        }

        return '<ol class="role-list">' . $cards . '</ol>';
    }

    /**
     * @param list<string> $items
     */
    private function chips(array $items, string $variant): string
    {
        if ($items === []) {
            return '';
        }

        return implode(' ', array_map(fn (string $i): string => '<span class="mui-badge ' . $variant . '">' . $this->esc($i) . '</span>', $items));
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }
}
