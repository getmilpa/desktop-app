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

use Milpa\Live\ValueObjects\ClientAssets;

/**
 * The client files each Desktop component ships, and the one route family that serves them
 * (greenhouse decisions/0211, "declared views").
 *
 * A Desktop component keeps its markup in a renderer, its CSS in its own file and its behaviour in its
 * own client module; this class is the map between the two — the package path a file lives at and the
 * URL the page loads it from. A renderer implementing
 * {@see \Milpa\Live\Contracts\Rendering\DeclaresClientAssets} answers {@see of()} for its own name and
 * NOTHING else, so `LiveBoot::html()` emits exactly the files the compiled page actually used, each once.
 *
 * The declaration is true by construction: {@see of()} names only files listed in {@see FILES}, and the
 * suite mutates that list against the package to prove every entry exists (a declaration pointing at a
 * missing file is a lying declaration — the `<link>` would 404 in silence).
 *
 * The route shape is `/desktop/assets/c/<component>.css` and `/desktop/assets/c/<component>.js`, served
 * by {@see \Milpa\DesktopApp\Controllers\AssetsController::component()} from
 * `resources/components/<component>/<component>.<ext>` — package files, so they carry no gate, like the
 * design-system stylesheets: a JSON 401 to a `<link>` or `<script>` breaks the page in silence.
 */
final class DesktopAssets
{
    /** Where the per-component files are served from — one segment, so the router's `{file}` captures `<name>.<ext>`. */
    public const string BASE = '/desktop/assets/c/';

    /** The shared runtime module (greenhouse decisions/0211, A4): the guard, the copy, the dismiss signal. Not a component. */
    public const string GUARD = 'desktop-guard';

    /**
     * The shell's event bus (greenhouse decisions/0211, D1). `window.MilpaShell` is the Desktop's
     * PUBLISHED extension point — four shipped modules consume it and a plugin drives its panels through
     * it — so it is a module of its own rather than a private channel folded into one of its consumers.
     */
    public const string BUS = 'desktop-shell-bus';

    /**
     * The Mercure connector (greenhouse decisions/0211, D1). It opens the ONE `EventSource`, reading the
     * hub's URL from a JSON data tag, and translates hub envelopes into the bus's facts and the shared
     * signals. A transport is not a surface either.
     */
    public const string HUB = 'desktop-hub';

    /**
     * The governed turn (greenhouse decisions/0211, C3). A RUNTIME module like the guard, not a component:
     * a turn is not a surface — nothing renders it — so no renderer declares it and the page does.
     */
    public const string TURN = 'desktop-turn';

    /**
     * The composer's slash commands (greenhouse decisions/0211, C4). A runtime module too: a command is
     * the house's own operation reached from the composer, and the popup that lists them is markup the
     * composer already owns.
     */
    public const string COMMANDS = 'desktop-commands';

    /**
     * The modules the PAGE declares — every component module is declared by the renderer that paints it,
     * and these five have no surface to be painted. Emitted first, in this order: the guard creates
     * `MilpaLive.desktop`, the bus creates `window.MilpaShell`, the hub subscribes the transport to it,
     * and the turn and the commands hang off the guard.
     *
     * @return list<string>
     */
    public static function runtimeModules(): array
    {
        return [self::GUARD, self::BUS, self::HUB, self::TURN, self::COMMANDS];
    }

    /**
     * Which component ships which files. A component absent from this map declares nothing — the
     * design system carries its whole look and it has no behaviour of its own yet.
     *
     * @var array<string, list<string>> component name => the extensions the package ships for it
     */
    private const array FILES = [
        'desktop-sidebar' => ['css', 'js'],
        'desktop-topbar' => ['css', 'js'],
        'desktop-tabs' => ['css', 'js'],
        'desktop-gate' => ['css', 'js'],
        'desktop-activity' => ['css', 'js'],
        'desktop-settings' => ['css', 'js'],
        'desktop-auth' => ['css', 'js'],
        'desktop-session-strip' => ['css'],
        'desktop-composer' => ['css', 'js'],
        'desktop-context' => ['css'],
        'desktop-work-board' => ['css', 'js'],
        'desktop-conversation' => ['css', 'js'],
        'desktop-user-message' => ['css'],
        // The three message kinds with behaviour of their own (greenhouse decisions/0211, C1): the
        // thinking block's streaming life, the answer's markdown and foot tools, the tool result's
        // reading and collapse. The plain kinds (user, task, system notice) are filled by the thread.
        'desktop-agent-message' => ['css', 'js'],
        'desktop-thinking' => ['css', 'js'],
        'desktop-tool-call' => ['css', 'js'],
        'desktop-task' => ['css'],
        'desktop-system-notice' => ['css'],
        'desktop-result-claim' => ['css', 'js'],
        // The screens the shell's template used to carry as raw HTML with their behaviour in its inline
        // script (greenhouse decisions/0211, phase D). `desktop-skills` and `desktop-statusbar` declare a
        // stylesheet and nothing else: they are read-only projections, so they have no module to ship.
        'desktop-capabilities' => ['css', 'js'],
        'desktop-decisions' => ['css', 'js'],
        'desktop-screens' => ['css', 'js'],
        'desktop-skills' => ['css'],
        'desktop-statusbar' => ['css'],
        // The Agent region of a HOST panel (greenhouse decisions/0211, slice 3): the root of the view
        // this package declares to milpa/admin. It ships the region's frame — the column its surfaces
        // sit in — and no module: the region writes no behaviour of its own, it composes surfaces that
        // bring theirs. The host emits this file like any other, from the declaration its renderer makes.
        'desktop-agent' => ['css'],
        self::GUARD => ['js'],
        self::BUS => ['js'],
        self::HUB => ['js'],
        self::TURN => ['js'],
        self::COMMANDS => ['js'],
    ];

    /**
     * What `$component` declares: its own stylesheet when the package ships one, its own module when it
     * ships one — never another component's, and empty when it ships neither.
     */
    public static function of(string $component): ClientAssets
    {
        $extensions = self::FILES[$component] ?? [];

        return new ClientAssets(
            scripts: \in_array('js', $extensions, true) ? [self::url($component, 'js')] : [],
            styles: \in_array('css', $extensions, true) ? [self::url($component, 'css')] : [],
        );
    }

    /** The URL one file is served at: `/desktop/assets/c/<component>.<ext>`. */
    public static function url(string $component, string $extension): string
    {
        return self::BASE . $component . '.' . $extension;
    }

    /**
     * Every component that ships at least one client file, in declaration order.
     *
     * @return list<string>
     */
    public static function declared(): array
    {
        return array_keys(self::FILES);
    }

    /**
     * The package path a served file name maps to, or null when the name is not one this package ships.
     *
     * The name is validated before it ever reaches the filesystem — `<component>.css|js` with the
     * component in {@see FILES} — so no traversal, no arbitrary read, and an unknown name is a 404
     * rather than a guess at a path.
     */
    public static function path(string $file): ?string
    {
        if (preg_match('/^([a-z0-9-]+)\.(css|js)$/', $file, $m) !== 1) {
            return null;
        }
        [, $component, $extension] = $m;
        if (!\in_array($extension, self::FILES[$component] ?? [], true)) {
            return null;
        }

        return \dirname(__DIR__, 2) . '/resources/components/' . $component . '/' . $component . '.' . $extension;
    }
}
