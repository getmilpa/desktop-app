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

namespace Milpa\DesktopApp;

/**
 * The mutable collector other plugins contribute the desktop shell's UI through (greenhouse decisions/0188).
 *
 * When {@see Controllers\ShellController} renders, it dispatches {@see ShellController::COMPOSE_EVENT}
 * carrying one of these in the payload; any plugin that subscribed to that event (in its own `boot()`)
 * appends sections here, and the controller renders them into the page. This is the seam Rod named:
 * "a plugin renders the UI, and other plugins have events they use to modify that same UI." It is
 * decoupled by construction — the shell never knows which plugins contribute; they meet only at the
 * event name.
 *
 * Sections keep insertion order, which is subscription-priority order (the dispatcher sorts handlers
 * by priority before calling them), so a contributor that must come first subscribes at a higher
 * priority. Contributed HTML is trusted plugin output — plugins are code the app deliberately
 * installed, not request input — so it is emitted verbatim; only the plugin id attribute is escaped.
 *
 * The developer experience (greenhouse decisions/0478): a plugin adds a dashboard panel with one call —
 * {@see addPanel()} for a titled panel the shell wraps in consistent card chrome (the ergonomic default),
 * or {@see addSection()} for a raw fragment that owns its own markup. Either way it lands as a component
 * on the client runtime; the plugin's own script then updates it live via `MilpaShell.panel('<id>')`.
 */
final class ShellComposition
{
    /** @var list<array{id: string, title: string|null, html: string}> */
    private array $sections = [];

    /** Append a raw section of shell HTML contributed by the plugin identified by `$pluginId`. */
    public function addSection(string $pluginId, string $html): void
    {
        $this->sections[] = ['id' => $pluginId, 'title' => null, 'html' => $html];
    }

    /** Append a titled dashboard panel; the shell wraps `$html` in its standard panel card. */
    public function addPanel(string $pluginId, string $title, string $html): void
    {
        $this->sections[] = ['id' => $pluginId, 'title' => $title, 'html' => $html];
    }

    /**
     * The contributed sections, in the order they were added (subscription-priority order).
     *
     * @return list<array{id: string, title: string|null, html: string}>
     */
    public function sections(): array
    {
        return $this->sections;
    }
}
