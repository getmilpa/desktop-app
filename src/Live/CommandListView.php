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
 * Renders the composer's command completion — the list the house serves (greenhouse decisions/0202).
 *
 * One option per command: the house's own (`/goal`, `/mode`, `/help`) and every user-invocable skill
 * (`/<skill-name>`), each with its description and usage. The popup is CSS state (`data-open`) driven by one
 * delegated handler in the shell — no per-instance x-data (Alpine double-inits dynamic x-data). The list is
 * the same one {@see \Milpa\DesktopApp\Data\DesktopData::commands()} serves as JSON to the parser, so what
 * completes is exactly what runs. Pure, so it is tested directly with fixtures.
 */
final class CommandListView
{
    /** The popup's element id — the shell's delegated handler and its CSS address it. */
    public const string ID = 'milpa-command-list';

    /**
     * The completion popup as HTML — one option per command, or nothing when there are no commands.
     *
     * @param list<array{name: string, kind: string, description: string, usage: string}> $commands
     */
    public function html(array $commands): string
    {
        if ($commands === []) {
            return '';
        }

        $options = '';
        foreach ($commands as $c) {
            $options .= '<button type="button" role="option" class="milpa-cmd" aria-selected="false"'
                . ' data-command="' . $this->esc($c['name']) . '" data-kind="' . $this->esc($c['kind']) . '">'
                . '<span class="milpa-cmd__name">/' . $this->esc($c['name']) . '</span>'
                . '<span class="milpa-cmd__desc">' . $this->esc($c['description']) . '</span>'
                . '<span class="milpa-cmd__usage">' . $this->esc($c['usage']) . '</span>'
                . '</button>';
        }

        return '<div id="' . self::ID . '" class="milpa-cmds" role="listbox" aria-label="Commands" data-open="0">'
            . $options
            . '</div>';
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }
}
