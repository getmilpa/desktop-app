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
 * Serves the Milpa Desktop dashboard over HTTP (greenhouse decisions/0188, 0478).
 *
 * The whole Desktop UI, built from Milpa components: a header with a live connection indicator and a
 * responsive grid of panels. Every panel is a component on the {@see runtimeScript()} client runtime —
 * it renders server-side and reacts to live events. The built-ins are the consent gate (the Desktop's
 * reason to exist), the activity stream, and the passkey doors; plugins add their own panels through the
 * composition seam ({@see COMPOSE_EVENT}) with `addPanel()` and drive them live via `MilpaShell.panel()`.
 *
 * Served by Milpa at a real origin, so the passkey ceremony (`/webauthn/*`) is same-origin; when a Mercure
 * hub is wired ({@see MercureConfig}, evidence/0474-0475) the grid updates with no poll.
 */
final class ShellController
{
    /** The event other plugins subscribe to (in their `boot()`) to contribute dashboard panels. */
    public const COMPOSE_EVENT = 'desktop.shell.compose';

    public function __construct(
        private readonly MilpaEventDispatcherInterface $events,
        private readonly ?MercureConfig $mercure = null,
    ) {
    }

    /** Serve the dashboard, composed with every plugin's contributed panels. */
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
        $panels = '';
        foreach ($composition->sections() as $section) {
            $title = $section['title'] !== null
                ? '<header class="panel-h"><h2>' . htmlspecialchars($section['title'], ENT_QUOTES) . '</h2></header>'
                : '';
            $panels .= sprintf(
                '<section class="panel" data-panel="%1$s" data-plugin="%1$s">%2$s<div class="panel-body" data-panel-body>%3$s</div></section>' . "\n",
                htmlspecialchars($section['id'], ENT_QUOTES),
                $title,
                $section['html'],
            );
        }

        return str_replace(
            ['<!--RUNTIME-->', '<!--PANELS-->', '<!--LIVE-->'],
            [$this->runtimeScript(), $panels, $this->connectScript()],
            $this->template(),
        );
    }

    /**
     * The client component runtime, always served (greenhouse decisions/0476, 0478).
     *
     * `MilpaShell` is the bridge between the live transport and the UI, and the panel API is the DX: a
     * component registers `on('<event>', cb)` / `onAny(cb)`, reads its panel body with `panel('<id>')`, and
     * tracks the connection with `onStatus(cb)`. Defined before the panels so a plugin's own script can
     * register against it; the connection that feeds it is {@see connectScript()}.
     */
    private function runtimeScript(): string
    {
        return <<<'HTML'
<script>
  window.MilpaShell = (function () {
    var byType = {}, anyHandlers = [], statusHandlers = [];
    return {
      on: function (type, cb) { (byType[type] = byType[type] || []).push(cb); },
      onAny: function (cb) { anyHandlers.push(cb); },
      onStatus: function (cb) { statusHandlers.push(cb); },
      emit: function (type, data) {
        (byType[type] || []).forEach(function (cb) { cb(data); });
        anyHandlers.forEach(function (cb) { cb(type, data); });
      },
      status: function (state) { statusHandlers.forEach(function (cb) { cb(state); }); },
      panel: function (id) {
        var p = document.querySelector('[data-panel="' + id + '"]');
        return p ? p.querySelector('[data-panel-body]') : null;
      }
    };
  })();
</script>
HTML;
    }

    /** Connect the runtime to the Mercure hub when one is wired; otherwise report an offline status. */
    private function connectScript(): string
    {
        if ($this->mercure === null) {
            return "<script>window.MilpaShell.status('offline');</script>";
        }

        $url = json_encode($this->mercure->publicUrl . '?topic=' . rawurlencode($this->mercure->topic), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return <<<HTML
<script>
  (function () {
    var es = new EventSource({$url}, { withCredentials: true });
    es.onopen = function () { window.MilpaShell.status('live'); };
    es.onerror = function () { window.MilpaShell.status('offline'); };
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
  :root {
    --bg: oklch(0.985 0.004 150);
    --surface: oklch(1 0 0);
    --surface-2: oklch(0.965 0.006 150);
    --ink: oklch(0.26 0.012 160);
    --muted: oklch(0.52 0.012 160);
    --line: oklch(0.26 0.012 160 / 0.14);
    --accent: oklch(0.58 0.13 150);
    --accent-ink: oklch(0.99 0.01 150);
    --shadow: 0 1px 2px oklch(0.26 0.02 160 / 0.06), 0 6px 20px oklch(0.26 0.02 160 / 0.06);
  }
  :root:not([data-theme="light"]) {
    @media (prefers-color-scheme: dark) {
      --bg: oklch(0.17 0.01 160); --surface: oklch(0.21 0.012 160); --surface-2: oklch(0.235 0.014 160);
      --ink: oklch(0.93 0.006 150); --muted: oklch(0.68 0.012 160); --line: oklch(0.93 0.01 150 / 0.14);
      --accent: oklch(0.74 0.14 150); --accent-ink: oklch(0.17 0.02 160);
      --shadow: 0 1px 2px oklch(0 0 0 / 0.3), 0 8px 24px oklch(0 0 0 / 0.35);
    }
  }
  :root[data-theme="dark"] {
    --bg: oklch(0.17 0.01 160); --surface: oklch(0.21 0.012 160); --surface-2: oklch(0.235 0.014 160);
    --ink: oklch(0.93 0.006 150); --muted: oklch(0.68 0.012 160); --line: oklch(0.93 0.01 150 / 0.14);
    --accent: oklch(0.74 0.14 150); --accent-ink: oklch(0.17 0.02 160);
    --shadow: 0 1px 2px oklch(0 0 0 / 0.3), 0 8px 24px oklch(0 0 0 / 0.35);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--ink);
    font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif;
    -webkit-font-smoothing: antialiased;
  }
  code, .mono { font-family: ui-monospace, "SF Mono", "JetBrains Mono", monospace; }
  a { color: var(--accent); text-underline-offset: 2px; }

  header.top {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: .85rem 1.4rem; background: color-mix(in oklab, var(--bg) 86%, transparent);
    backdrop-filter: blur(8px); border-bottom: 1px solid var(--line);
  }
  .brand { display: flex; align-items: baseline; gap: .55rem; }
  .brand b { font-size: 1.02rem; font-weight: 650; letter-spacing: -0.01em; }
  .brand span { font-size: .82rem; color: var(--muted); }
  .conn { display: inline-flex; align-items: center; gap: .45rem; font-size: .82rem; color: var(--muted); }
  .conn .dot { width: .55rem; height: .55rem; border-radius: 50%; background: var(--muted); }
  .conn[data-state="live"] { color: var(--accent); }
  .conn[data-state="live"] .dot { background: var(--accent); box-shadow: 0 0 0 0 var(--accent); animation: pulse 2.4s ease-out infinite; }
  @keyframes pulse { 0% { box-shadow: 0 0 0 0 color-mix(in oklab, var(--accent) 55%, transparent); } 70%,100% { box-shadow: 0 0 0 .5rem transparent; } }

  main { max-width: 68rem; margin: 0 auto; padding: 1.5rem 1.4rem 4rem; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr)); gap: 1rem; align-items: start; }
  .panel {
    background: var(--surface); border: 1px solid var(--line); border-radius: 14px;
    padding: 1rem 1.1rem 1.1rem; box-shadow: var(--shadow); min-width: 0;
  }
  .panel-h { display: flex; align-items: center; justify-content: space-between; margin: 0 0 .7rem; }
  .panel h2 { font-size: .95rem; font-weight: 620; margin: 0; letter-spacing: -0.005em; }
  .panel p { margin: 0 0 .7rem; color: var(--muted); font-size: .9rem; }
  .panel .lede { color: var(--ink); }

  .btn {
    display: inline-flex; align-items: center; gap: .4rem; text-decoration: none; cursor: pointer;
    border: 1px solid var(--line); background: var(--surface-2); color: var(--ink);
    border-radius: 9px; padding: .5rem .85rem; font-size: .88rem; font-weight: 550;
    transition: background .15s ease, transform .1s ease;
  }
  .btn:hover { background: color-mix(in oklab, var(--accent) 10%, var(--surface-2)); }
  .btn:active { transform: translateY(1px); }
  .btn.primary { background: var(--accent); color: var(--accent-ink); border-color: transparent; }
  .btn.primary:hover { background: color-mix(in oklab, var(--accent) 88%, black); }
  .actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .3rem; }

  .kv { font-size: .88rem; margin: .15rem 0; }
  .kv b { color: var(--muted); font-weight: 550; margin-right: .35rem; }
  ul.feed { list-style: none; margin: 0; padding: 0; font: 12.5px/1.5 ui-monospace, monospace; max-height: 15rem; overflow: auto; }
  ul.feed li { padding: .3rem .5rem; border-radius: 8px; background: var(--surface-2); margin: .25rem 0; word-break: break-word; animation: rise .18s cubic-bezier(.2,.7,.3,1); }
  @keyframes rise { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
  .muted { color: var(--muted); }

  /* The consent gate: elevated, spans the whole grid, demands the eye when active. */
  .panel.gate { grid-column: 1 / -1; border-color: color-mix(in oklab, var(--accent) 55%, var(--line)); box-shadow: 0 0 0 1px color-mix(in oklab, var(--accent) 40%, transparent), var(--shadow); }
  .panel.gate .badge { font-size: .72rem; color: var(--accent); border: 1px solid color-mix(in oklab, var(--accent) 45%, transparent); border-radius: 999px; padding: .1rem .5rem; }

  @media (prefers-reduced-motion: reduce) {
    .conn[data-state="live"] .dot { animation: none; }
    ul.feed li { animation: none; }
    .btn { transition: none; }
  }
</style>
<!--RUNTIME-->

<header class="top">
  <div class="brand"><b>Milpa Desktop</b><span>served by Milpa, one origin</span></div>
  <div class="conn" id="milpa-conn" data-state="idle"><span class="dot"></span><span data-conn-label>connecting…</span></div>
</header>

<main>
  <div class="grid">

    <!-- The consent gate: hidden until an agent parks one, then rendered live from a gate.opened event. -->
    <section class="panel gate" id="milpa-gate" hidden>
      <div class="panel-h"><h2>An agent is asking to act</h2><span class="badge">awaiting you</span></div>
      <p class="kv"><b>Operation</b><code data-gate-op></code></p>
      <p class="kv"><b>Arguments</b><code data-gate-args></code></p>
      <div class="actions">
        <a class="btn primary" data-gate-approve href="#">Approve with passkey</a>
        <button type="button" class="btn" data-gate-dismiss>Dismiss</button>
      </div>
    </section>

    <section class="panel" data-panel="activity">
      <div class="panel-h"><h2>Activity</h2></div>
      <p>Every change the runtime receives, live.</p>
      <ul class="feed" id="milpa-activity" aria-live="polite"><li class="muted">waiting for changes…</li></ul>
    </section>

    <section class="panel" data-panel="passkey">
      <div class="panel-h"><h2>Passkey</h2></div>
      <p>The ceremony runs in this origin — no <code>file://</code>, no separate window.</p>
      <div class="actions">
        <a class="btn" href="/webauthn/enroll">Register a passkey</a>
        <a class="btn" href="/webauthn/intent?operation=capabilities.enable&amp;arguments=%7B%22name%22%3A%22a2a%22%7D&amp;session=ses-A">Approve a sample op</a>
      </div>
    </section>

    <!-- Panels other plugins contribute through desktop.shell.compose are rendered here. -->
    <!--PANELS-->

  </div>
</main>

<script>
  (function () {
    var conn = document.getElementById('milpa-conn');
    var label = conn.querySelector('[data-conn-label]');
    var text = { live: 'live', offline: 'offline', idle: 'connecting…' };
    window.MilpaShell.onStatus(function (state) {
      conn.setAttribute('data-state', state);
      label.textContent = text[state] || state;
    });

    var list = document.getElementById('milpa-activity');
    window.MilpaShell.onAny(function (type, data) {
      var placeholder = list.querySelector('.muted');
      if (placeholder) { list.removeChild(placeholder); }
      var li = document.createElement('li');
      li.textContent = type + ' · ' + JSON.stringify(data);
      list.insertBefore(li, list.firstChild);
    });

    var gate = document.getElementById('milpa-gate');
    window.MilpaShell.on('gate.opened', function (g) {
      var args = (g && g.arguments) || {};
      var href = '/webauthn/intent?operation=' + encodeURIComponent(g.operation)
        + '&arguments=' + encodeURIComponent(JSON.stringify(args))
        + '&session=' + encodeURIComponent(g.session || '');
      gate.querySelector('[data-gate-op]').textContent = g.operation || '';
      gate.querySelector('[data-gate-args]').textContent = JSON.stringify(args);
      gate.querySelector('[data-gate-approve]').setAttribute('href', href);
      gate.hidden = false;
    });
    gate.querySelector('[data-gate-dismiss]').addEventListener('click', function () { gate.hidden = true; });
  })();
</script>
<!-- The connection to the Mercure hub is rendered here when a hub is wired; it feeds MilpaShell. -->
<!--LIVE-->
HTML;
    }
}
