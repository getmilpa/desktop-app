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

use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\DesktopApp\ShellComposition;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the desktop shell over HTTP (greenhouse decisions/0188).
 *
 * This is the seam where the app's own UI lives, served by Milpa so a host loads it at a real origin.
 * The shell PROVES the architecture: it declares it is Milpa-served, and it links the passkey ceremony
 * (`/webauthn/enroll`, `/webauthn/intent`) which now shares this origin — so the ceremony no longer
 * needs a separate `file://`-workaround window.
 *
 * The shell is also EXTENSIBLE by other plugins: on every render it dispatches {@see COMPOSE_EVENT}
 * with a {@see ShellComposition} in the payload, and any plugin that subscribed to that event (in its
 * own `boot()`) appends sections that this controller renders into the page. That is the reactive-UI
 * seam of 0188 in its first, render-time form — a plugin renders the UI and other plugins modify it,
 * decoupled through the event name.
 *
 * When a Mercure hub is wired ({@see MercureConfig}, evidence/0474-0475), the shell also carries a live
 * client: it sets the subscriber JWT as the `mercureAuthorization` cookie and opens an `EventSource` on the
 * hub's public URL, so shell changes published to the hub arrive with no poll. Absent a hub, the page ships
 * without the live client and the `/desktop/events` feed (0473) remains the transport.
 */
final class ShellController
{
    /** The event other plugins subscribe to (in their `boot()`) to contribute shell sections. */
    public const COMPOSE_EVENT = 'desktop.shell.compose';

    public function __construct(
        private readonly MilpaEventDispatcherInterface $events,
        private readonly ?MercureConfig $mercure = null,
    ) {
    }

    /** Serve the desktop shell page, composed with every plugin's contributed sections. */
    public function shell(ServerRequestInterface $request): ResponseInterface
    {
        $composition = new ShellComposition();
        $this->events->dispatch(self::COMPOSE_EVENT, ['composition' => $composition]);

        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'];
        if ($this->mercure !== null) {
            // The hub reads the subscriber JWT from this cookie; the browser sends it with EventSource.
            $headers['Set-Cookie'] = 'mercureAuthorization=' . $this->mercure->subscriberJwt() . '; Path=/; SameSite=Lax';
        }

        return new Response(200, $headers, $this->html($composition));
    }

    private function html(ShellComposition $composition): string
    {
        $extensions = '';
        foreach ($composition->sections() as $section) {
            $extensions .= sprintf(
                '  <section class="card ext" data-plugin="%s">%s</section>' . "\n",
                htmlspecialchars($section['id'], ENT_QUOTES),
                $section['html'],
            );
        }

        return str_replace(
            ['<!--RUNTIME-->', '<!--GATE-->', '<!--EXTENSIONS-->', '<!--LIVE-->'],
            [$this->runtimeScript(), $this->gateComponent(), $extensions, $this->connectScript()],
            $this->template(),
        );
    }

    /**
     * The consent-gate component (greenhouse decisions/0476, 0477) — the Desktop's reason to exist.
     *
     * A real reactive panel built on {@see runtimeScript()}: when an agent parks a gate it publishes a
     * `gate.opened` event carrying the operation, its arguments and the session; this component renders that
     * live and points "Approve with passkey" at the app's own same-origin ceremony (`/webauthn/intent`,
     * evidence/0465-0467), so the human sees exactly what is being asked and answers with their key. Minting
     * the grant and resuming the agent's parked turn are the server-side D-01 residue; this closes the loop
     * from "agent asks" to "human approves at a real origin".
     */
    private function gateComponent(): string
    {
        return <<<'HTML'
<div class="card gate" id="milpa-gate" hidden>
  <h2>An agent is asking to act</h2>
  <p>Operation: <code data-gate-op></code></p>
  <p>Arguments: <code data-gate-args></code></p>
  <p>
    <a class="btn" data-gate-approve href="#">Approve with passkey</a>
    &nbsp;
    <button type="button" class="btn" data-gate-dismiss>Dismiss</button>
  </p>
</div>
<script>
  (function () {
    var el = document.getElementById('milpa-gate');
    window.MilpaShell.on('gate.opened', function (gate) {
      var args = (gate && gate.arguments) || {};
      var href = '/webauthn/intent?operation=' + encodeURIComponent(gate.operation)
        + '&arguments=' + encodeURIComponent(JSON.stringify(args))
        + '&session=' + encodeURIComponent(gate.session || '');
      el.querySelector('[data-gate-op]').textContent = gate.operation || '';
      el.querySelector('[data-gate-args]').textContent = JSON.stringify(args);
      el.querySelector('[data-gate-approve]').setAttribute('href', href);
      el.hidden = false;
    });
    el.querySelector('[data-gate-dismiss]').addEventListener('click', function () { el.hidden = true; });
  })();
</script>
HTML;
    }

    /**
     * The client component runtime, always served (greenhouse decisions/0476).
     *
     * `MilpaShell` is the bridge between the live transport and the UI: a component registers a handler with
     * `MilpaShell.on('<event>', cb)` (or `onAny`) and the runtime calls it when that event arrives. Defined
     * before the contributed sections so a plugin's own script can register against it; the connection that
     * feeds it is {@see connectScript()}. This is the reactive-renderer contract of 0188 in its first form.
     */
    private function runtimeScript(): string
    {
        return <<<'HTML'
<script>
  window.MilpaShell = (function () {
    var byType = {}, anyHandlers = [];
    return {
      on: function (type, cb) { (byType[type] = byType[type] || []).push(cb); },
      onAny: function (cb) { anyHandlers.push(cb); },
      emit: function (type, data) {
        (byType[type] || []).forEach(function (cb) { cb(data); });
        anyHandlers.forEach(function (cb) { cb(type, data); });
      }
    };
  })();
</script>
HTML;
    }

    /** Connect the runtime to the Mercure hub when one is wired; empty otherwise (static + poll fallback). */
    private function connectScript(): string
    {
        if ($this->mercure === null) {
            return '';
        }

        $url = json_encode($this->mercure->publicUrl . '?topic=' . rawurlencode($this->mercure->topic), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return <<<HTML
<script>
  (function () {
    var es = new EventSource({$url}, { withCredentials: true });
    es.onmessage = function (e) {
      var env; try { env = JSON.parse(e.data); } catch (err) { return; }
      window.MilpaShell.emit(env.event, env.data);
    };
  })();
</script>
HTML;
    }

    private function template(): string
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
  ul.feed { list-style: none; margin: 0; padding: 0; font: 13px/1.5 ui-monospace, monospace; }
  ul.feed li { padding: .25rem .5rem; border-radius: 6px; background: color-mix(in oklab, currentColor 6%, transparent); margin: .2rem 0; }
  .muted { opacity: .55; }
  .card.gate { border-color: color-mix(in oklab, currentColor 45%, transparent); border-width: 2px; }
  .card.gate h2 { opacity: 1; }
  button.btn { background: none; color: inherit; cursor: pointer; }
</style>
<!--RUNTIME-->
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

<!-- The consent gate: hidden until an agent parks one, then rendered live from a gate.opened event. -->
<!--GATE-->

<!-- Sections other plugins contribute through desktop.shell.compose are rendered here. -->
<!--EXTENSIONS-->

<div class="card">
  <h2>Activity</h2>
  <p>A built-in reactive component: every shell change the runtime receives lands here, live.</p>
  <ul class="feed" id="milpa-activity"><li class="muted">waiting for changes…</li></ul>
</div>
<script>
  (function () {
    var list = document.getElementById('milpa-activity');
    window.MilpaShell.onAny(function (type, data) {
      var placeholder = list.querySelector('.muted');
      if (placeholder) { list.removeChild(placeholder); }
      var li = document.createElement('li');
      li.textContent = type + ' · ' + JSON.stringify(data);
      list.appendChild(li);
    });
  })();
</script>

<!-- The connection to the Mercure hub is rendered here when a hub is wired; it feeds MilpaShell. -->
<!--LIVE-->
<div class="card">
  <h2>What comes next</h2>
  <p>Other plugins extend this shell by subscribing to <code>desktop.shell.compose</code>, and push live
  changes through <code>milpa/mercure</code>. The full UI as Milpa components is the arc
  (greenhouse decisions/0188).</p>
</div>
HTML;
    }
}
