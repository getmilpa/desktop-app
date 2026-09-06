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
        'desktop-conversation' => ['css'],
        'desktop-user-message' => ['css'],
        'desktop-agent-message' => ['css'],
        'desktop-thinking' => ['css'],
        'desktop-tool-call' => ['css'],
        'desktop-task' => ['css'],
        'desktop-system-notice' => ['css'],
        'desktop-result-claim' => ['css'],
        self::GUARD => ['js'],
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
