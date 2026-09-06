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

namespace Milpa\DesktopApp\Controllers;

use Milpa\DesktopApp\Live\DesktopAssets;
use Milpa\Http\Routing\RouteResult;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the vendored Milpa design system (@milpa/design) as static CSS (greenhouse decisions/0479), and
 * the per-component files each declared view brings with it (greenhouse decisions/0211).
 *
 * The dashboard is built from the real design system — the `tierra/oro/olivo` tokens and the `mui-*`
 * components — so the shell links these two stylesheets instead of hand-rolling its look. They are shipped
 * inside the package (`assets/milpa/`) and served here with a long immutable cache; the content never
 * changes for a given release.
 *
 * On top of that, a Desktop component keeps its CSS in its own file and its behaviour in its own client
 * module: {@see component()} serves `GET /desktop/assets/c/<component>.css|js` from
 * `resources/components/<component>/<component>.<ext>`. The name is validated against
 * {@see DesktopAssets::path()} — the map the renderers declare from — before it ever reaches the
 * filesystem, so an unknown name is a 404, never a guess at a path. Like the design-system stylesheets
 * these are PACKAGE files and carry no gate: a JSON 401 to a `<link>` or `<script>` breaks the page in
 * silence.
 *
 * They do NOT carry the design system's immutable year, though, and the difference is the point: a
 * component's URL has no version in it, and its file changes with every release while the URL stays
 * `/desktop/assets/c/desktop-tabs.js`. An immutable year would leave a browser running last release's
 * behaviour against this release's markup — the `x-data` factory names and the `data-*` hooks are a
 * CONTRACT between the two halves of a component, so a stale half is a dead surface. They are served
 * with the same hour the package's other behaviour files get
 * ({@see \Milpa\DesktopApp\Controllers\LiveController::asset()}): one cache policy for everything that
 * carries behaviour, and an upgrade is live within the hour instead of within the year.
 */
final class AssetsController
{
    /**
     * What a file whose URL carries no version may be cached for: an hour, revalidated after it.
     *
     * The same value {@see \Milpa\DesktopApp\Controllers\LiveController::asset()} serves the runtime with
     * — one policy for every file that carries BEHAVIOUR, so an upgrade cannot leave a browser running a
     * module from a previous release against this release's markup.
     */
    public const string BEHAVIOUR_CACHE = 'public, max-age=3600';

    /** The design-system tokens (colors, type, spacing, motion — dark-first). */
    public function tokens(ServerRequestInterface $request): ResponseInterface
    {
        return $this->css('tokens.css');
    }

    /** The design-system component bundle (`mui-*`: shell, sidebar, tabs, cards, gate, …). */
    public function bundle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->css('bundle.css');
    }

    /**
     * One declared view's own file: `GET /desktop/assets/c/<component>.css` or `…/<component>.js`.
     *
     * The route captures the whole last segment (`{file}`), so one route family serves both extensions;
     * the name is resolved to a package path only when a renderer actually declares it.
     *
     * Cached for an hour, not for the design system's year: the URL carries no version, so an immutable
     * answer would pin a released component's markup to a previous release's module.
     */
    public function component(ServerRequestInterface $request): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::ATTRIBUTE);
        $file = $result instanceof RouteResult ? (string) $result->parameter('file', '') : '';
        $path = $file === '' ? null : DesktopAssets::path($file);
        $body = $path !== null && is_file($path) ? (string) file_get_contents($path) : '';

        if ($body === '') {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'not found');
        }

        return new Response(
            200,
            [
                'Content-Type' => str_ends_with($file, '.js') ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8',
                'Cache-Control' => self::BEHAVIOUR_CACHE,
            ],
            $body,
        );
    }

    private function css(string $file): ResponseInterface
    {
        $path = \dirname(__DIR__, 2) . '/assets/milpa/' . $file;
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response(
            $body === '' ? 404 : 200,
            ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'public, max-age=31536000, immutable'],
            $body,
        );
    }
}
