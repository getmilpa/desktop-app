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

namespace Milpa\DesktopApp\Http;

use Milpa\DesktopApp\I18n\Catalog;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The Desktop's default gate: only requests from the loopback interface get through (greenhouse
 * decisions/0209 — the Desktop behind the same door as the admin).
 *
 * A fresh app has no identity wired, and a shell that runs governed turns with no gate at all is a door
 * left open on the LAN. This is the posture until the app declares `desktop.middleware` — a list of
 * PSR-15 middleware the plugin attaches to every shell route (the assets excepted), where a passkey or
 * scope gate takes this one's place. Fails closed: no remote address means no answer.
 *
 * The refusal has two faces, because the shell has two callers: a browser loading a page (a `GET` that
 * accepts `text/html`) gets a small HTML page that says why; everything else — the shell's own `fetch()`
 * calls, a curl — gets `403 {ok:false, error:'loopback_only'}`, which the shell's guard tells as a notice.
 * A JSON 401/403 to a `<link>` or `<script>` would break the page silently, which is why the assets are
 * never behind this gate.
 *
 * A conscious duplication of milpa/admin's `LoopbackOnlyMiddleware`: the Desktop takes no dependency on
 * the admin, and the neutral home for one shared gate would be milpa/app-runtime. Until it graduates
 * there, each package carries its own copy of the same rule.
 */
final class LoopbackOnlyMiddleware implements MiddlewareInterface
{
    /** The `error` the JSON refusal carries. */
    public const ERROR = 'loopback_only';

    public function __construct(private readonly Catalog $catalog = new Catalog())
    {
    }

    /** Lets a loopback request through and answers 403 to everything else — HTML for a browser page load, JSON otherwise. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $address = \is_string($address) ? $address : '';

        if (self::isLoopback($address)) {
            return $handler->handle($request);
        }

        if (self::wantsHtml($request)) {
            return new Response(403, ['Content-Type' => 'text/html; charset=utf-8'], $this->page());
        }

        return new Response(
            403,
            ['Content-Type' => 'application/json; charset=utf-8'],
            (string) json_encode(['ok' => false, 'error' => self::ERROR], \JSON_THROW_ON_ERROR),
        );
    }

    /** True for IPv4 127.0.0.0/8 and IPv6 ::1 (also in its IPv4-mapped form). */
    public static function isLoopback(string $address): bool
    {
        if ($address === '::1' || $address === '::ffff:127.0.0.1') {
            return true;
        }

        return str_starts_with($address, '127.') && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /** A browser loading a page: a GET whose Accept names text/html. The shell's fetch() calls do not. */
    private static function wantsHtml(ServerRequestInterface $request): bool
    {
        return strtoupper($request->getMethod()) === 'GET'
            && str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');
    }

    /** The refusal as a small, shell-less page — the reason and the key to declare, nothing else. */
    private function page(): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $title = $e($this->catalog->tr('gate.loopback.title'));

        return '<!doctype html><html lang="' . $e($this->catalog->locale()) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#1a140f;color:#f3ede4}main{max-width:44ch;padding:2rem}h1{font-size:1.25rem;margin:0 0 .75rem}p{margin:0;line-height:1.5;color:#c9bfb2}code{color:#e6c37a}</style>'
            . '</head><body><main><h1>' . $title . '</h1><p>' . $e($this->catalog->tr('gate.loopback')) . '</p></main></body></html>';
    }
}
