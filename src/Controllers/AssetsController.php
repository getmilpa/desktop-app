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

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the vendored Milpa design system (@milpa/design) as static CSS (greenhouse decisions/0479).
 *
 * The dashboard is built from the real design system — the `tierra/oro/olivo` tokens and the `mui-*`
 * components — so the shell links these two stylesheets instead of hand-rolling its look. They are shipped
 * inside the package (`assets/milpa/`) and served here with a long immutable cache; the content never
 * changes for a given release.
 */
final class AssetsController
{
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
