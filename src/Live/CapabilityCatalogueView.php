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
 * Renders the capability catalogue — INSTALLED and AVAILABLE, side by side (greenhouse decisions/0193).
 *
 * A pure view over the runtime's capability answer: the human sees the same list the agent reads, and each
 * available one carries a one-click Enable that installs it through the gated `capabilities:enable` over HTTP.
 * Pure so it is tested directly with fixtures — the render branches do not depend on a booted runtime.
 */
final class CapabilityCatalogueView
{
    /**
     * The catalogue as HTML: an Installed section and an Available section, each capability a card.
     *
     * @param list<array<string, mixed>> $installed
     * @param list<array<string, mixed>> $available
     */
    public function html(array $installed, array $available): string
    {
        return '<div class="cap-grid">'
            . '<section><p class="cap-col__head">Installed · ' . \count($installed) . '</p><div class="cap-stack">'
            . $this->installedCards($installed) . '</div></section>'
            . '<section><p class="cap-col__head">Available · ' . \count($available) . '</p><div class="cap-stack">'
            . $this->availableCards($available) . '</div></section>'
            . '</div><p class="cap-msg" id="milpa-cap-msg" role="status" hidden></p>';
    }

    /** @param list<array<string, mixed>> $installed */
    private function installedCards(array $installed): string
    {
        if ($installed === []) {
            return '<p class="mui-empty" style="color:var(--text-muted)">Only the catalogue — nothing installed reports here.</p>';
        }

        $cards = '';
        foreach ($installed as $c) {
            $id = $this->named($c);
            $title = $this->esc($c['title'] ?? '');
            $sub = $this->line($c['provides'] ?? ($c['unlocks'] ?? ''));
            $cards .= '<div class="cap-card"><div class="cap-card__head"><span class="cap-card__name">' . $id . '</span>'
                . '<span class="mui-badge mui-badge--success">installed</span></div>'
                . ($title !== '' ? '<p class="cap-card__desc">' . $title . '</p>' : '')
                . ($sub !== '' ? '<p class="cap-card__sub">' . $sub . '</p>' : '') . '</div>';
        }

        return $cards;
    }

    /** @param list<array<string, mixed>> $available */
    private function availableCards(array $available): string
    {
        if ($available === []) {
            return '<p class="mui-empty" style="color:var(--text-muted)">Everything available is installed.</p>';
        }

        $cards = '';
        foreach ($available as $c) {
            $pkg = \is_string($c['package'] ?? null) ? $c['package'] : '';
            $cmd = \is_string($c['command'] ?? null) ? $c['command'] : ('composer require ' . $pkg);
            $title = $this->esc($c['title'] ?? '');
            $unlocks = $this->line($c['unlocks'] ?? '');
            $cards .= '<div class="cap-card" data-cap-row="' . $this->esc($pkg) . '"><div class="cap-card__head"><span class="cap-card__name">' . $this->esc($pkg) . '</span>'
                . '<button type="button" class="mui-btn mui-btn--primary mui-btn--sm" data-cap-enable="' . $this->esc($pkg) . '" data-cap-cmd="' . $this->esc($cmd) . '">Enable</button></div>'
                . ($title !== '' ? '<p class="cap-card__desc">' . $title . '</p>' : '')
                . ($unlocks !== '' ? '<p class="cap-card__sub">Unlocks: ' . $unlocks . '</p>' : '') . '</div>';
        }

        return $cards;
    }

    private function esc(mixed $v): string
    {
        return htmlspecialchars(\is_string($v) ? $v : '', ENT_QUOTES);
    }

    private function line(mixed $v): string
    {
        if (\is_array($v)) {
            $v = implode(', ', array_filter($v, 'is_string'));
        }

        return $this->esc($v);
    }

    /** @param array<string, mixed> $c */
    private function named(array $c): string
    {
        $id = \is_string($c['id'] ?? null) && $c['id'] !== '' ? $c['id'] : (\is_string($c['package'] ?? null) ? $c['package'] : '');

        return $this->esc($id);
    }
}
