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
 * Serves the desktop shell over HTTP (greenhouse decisions/0188).
 *
 * This is the seam where the app's own UI lives, served by Milpa so a host loads it at a real origin.
 * The first slice serves a shell that PROVES the architecture: it declares it is Milpa-served, and it
 * links the passkey ceremony (`/webauthn/enroll`, `/webauthn/intent`) which now shares this origin —
 * so the ceremony no longer needs a separate `file://`-workaround window. The full renderer migration,
 * the live components and the reactive event bus are the arc; this establishes that a plugin can serve
 * the shell and that the shell is same-origin as the doors it must reach.
 */
final class ShellController
{
    /** Serve the desktop shell page. */
    public function shell(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            $this->html(),
        );
    }

    private function html(): string
    {
        return <<<'HTML'
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Milpa Desktop</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 15px/1.6 system-ui, sans-serif; max-width: 40rem; margin: 3rem auto; padding: 0 1.2rem; }
  h1 { font-size: 1.4rem; margin: 0 0 .2rem; }
  .sub { opacity: .7; margin: 0 0 1.6rem; }
  .card { border: 1px solid color-mix(in oklab, currentColor 18%, transparent); border-radius: 10px; padding: 1rem 1.1rem; margin: .8rem 0; }
  .card h2 { font-size: 1rem; margin: 0 0 .3rem; }
  .card p { margin: 0 0 .6rem; opacity: .85; }
  a.btn { display: inline-block; text-decoration: none; border: 1px solid currentColor; border-radius: 8px; padding: .45rem .9rem; font-size: .92rem; }
  code { font-family: ui-monospace, monospace; }
</style>
<h1>Milpa Desktop</h1>
<p class="sub">This shell is served by Milpa itself, over HTTP — one app, one origin.</p>

<div class="card">
  <h2>The desktop channel is the web channel</h2>
  <p>You are looking at the app's own UI, served by the <code>milpa/desktop-app</code> plugin. An
  Electron host loads this page with <code>loadURL</code>; a browser opens the same URL. There is no
  <code>file://</code> renderer, so everything that needs a real origin just works here.</p>
</div>

<div class="card">
  <h2>Passkey, in this origin</h2>
  <p>Because the shell shares its origin with the app's own doors, the passkey ceremony runs right
  here — no separate window, no workaround.</p>
  <p>
    <a class="btn" href="/webauthn/enroll">Register a passkey</a>
    &nbsp;
    <a class="btn" href="/webauthn/intent?operation=capabilities.enable&amp;arguments=%7B%22name%22%3A%22a2a%22%7D&amp;session=ses-A">Approve an operation</a>
  </p>
</div>

<div class="card">
  <h2>What comes next</h2>
  <p>This shell is the seam. The full UI as Milpa components, the reactive event bus
  (websockets / milpa/mercure) and the events other plugins hook to change this UI are the arc
  (greenhouse decisions/0188).</p>
</div>
HTML;
    }
}
