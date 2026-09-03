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

use Milpa\DesktopApp\Data\DesktopData;
use Milpa\DesktopApp\Live\MercureConfig;
use Milpa\DesktopApp\ShellComposition;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the Milpa Desktop dashboard — the real UI, built from the Milpa design system (greenhouse decisions/0479).
 *
 * The whole Desktop app frame implemented from the canonical wireframes ("Wireframes de Milpa Design"): the
 * window chrome, the `mui-shell` grid (sidebar with sessions + a topbar + tabbed main), the composer and the
 * status bar — styled by the vendored `@milpa/design` system (`tierra/oro/olivo` tokens, `mui-*` components,
 * Space Grotesk / Space Mono), dark-first with a light toggle, served by {@see AssetsController}.
 *
 * Every panel is a component on the {@see runtimeScript()} client runtime: the consent gate (the design's
 * `mui-gate`, rendered live when an agent parks one), the Activity tab (the live event stream), and any panel
 * a plugin contributes through {@see COMPOSE_EVENT} with `addPanel()`, driven live via `MilpaShell.panel()`.
 * When a Mercure hub is wired ({@see MercureConfig}) the UI updates with no poll; the passkey ceremony is
 * same-origin. UI, UX and DX, all Milpa components.
 */
final class ShellController
{
    /** The event other plugins subscribe to (in their `boot()`) to contribute dashboard panels. */
    public const COMPOSE_EVENT = 'desktop.shell.compose';

    public function __construct(
        private readonly MilpaEventDispatcherInterface $events,
        private readonly ?MercureConfig $mercure = null,
        private readonly ?DesktopData $data = null,
    ) {
    }

    /** Serve the dashboard, composed with every plugin's contributed panels. */
    public function shell(ServerRequestInterface $request): ResponseInterface
    {
        // A sidebar click selects a session via `?session=<id>`; the data seam loads that one's counters,
        // context and conversation (an unknown or malformed id is ignored — the newest session stands).
        $params = $request->getQueryParams();
        if (isset($params['session']) && is_string($params['session'])) {
            $this->data?->select($params['session']);
        }

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
            $header = $section['title'] !== null
                ? '<div class="mui-card__header"><h2 class="mui-card__title">' . htmlspecialchars($section['title'], ENT_QUOTES) . '</h2></div>'
                : '';
            $panels .= sprintf(
                '<section class="mui-card" data-panel="%1$s" data-plugin="%1$s">%2$s<div class="mui-card__body" data-panel-body>%3$s</div></section>' . "\n",
                htmlspecialchars($section['id'], ENT_QUOTES),
                $header,
                $section['html'],
            );
        }
        if ($panels === '') {
            $panels = '<p class="mui-empty">No plugin has contributed a panel yet. A plugin adds one with '
                . '<code>ShellComposition::addPanel()</code>.</p>';
        }

        return str_replace(
            [
                '<!--RUNTIME-->', '<!--PANELS-->', '<!--CAPABILITIES-->', '<!--ENDPOINT-->',
                '<!--SESSIONS-->', '<!--STATUS-->', '<!--WORK-->', '<!--AUDIT-->', '<!--PROJECTION-->', '<!--COMPOSER-->', '<!--AUTHMODEL-->', '<!--LIVE-->', '<!--TOPHEADER-->', '<!--TOPBARACTIONS-->',
            ],
            [
                $this->runtimeScript(), $panels, $this->capabilitiesRows(), $this->endpointValue(),
                $this->sessionsList(), $this->statusCounters(), $this->workBoard(), $this->auditStream(), $this->projectionStats(), $this->composer(), $this->authModelLabel(), $this->connectScript(), $this->topbarHeader(), $this->topbarActions(),
            ],
            $this->template(),
        );
    }

    /** The installed-capabilities table rows, from real runtime data (greenhouse decisions/0481). */
    private function capabilitiesRows(): string
    {
        $caps = $this->data?->capabilities() ?? [];
        if ($caps === []) {
            return '<tr><td colspan="4" class="mui-empty">No capabilities reported by the runtime.</td></tr>';
        }

        $rows = '';
        foreach ($caps as $cap) {
            $rows .= sprintf(
                '<tr><td class="mui-table__lead">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars($cap['name'], ENT_QUOTES),
                htmlspecialchars($cap['version'], ENT_QUOTES),
                htmlspecialchars($cap['type'], ENT_QUOTES),
                htmlspecialchars($cap['author'], ENT_QUOTES),
            );
        }

        return $rows;
    }

    /** The model endpoint: the persisted setting if saved (0483), else the configured one. */
    private function endpointValue(): string
    {
        $settings = $this->data?->settings() ?? [];
        $saved = $settings['endpoint'] ?? null;
        $endpoint = is_string($saved) && $saved !== '' ? $saved : ($this->data?->model()['endpoint'] ?? 'http://llama.local:11438');

        return htmlspecialchars($endpoint, ENT_QUOTES);
    }

    /** Format a token count as "9.25K". */
    private function kfmt(int $n): string
    {
        return number_format($n / 1000, 2) . 'K';
    }

    /**
     * The composer with floating Context and Session panels (wireframe 3a) — clean data over the tight bar.
     *
     * The panels open when their figures in the composer are clicked and close when the user types; the
     * bottom status bar drops to one line. Every number is real: the context window and its usage from
     * {@see DesktopData::context()}, the session counters from {@see DesktopData::counters()}.
     */
    private function composer(): string
    {
        $ctx = $this->data?->context() ?? ['tokens' => 0, 'window' => 32768, 'used_pct' => 0, 'free' => 32768];
        $c = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];
        $model = htmlspecialchars($this->data?->model()['model'] ?? 'qwen3.8-27b', ENT_QUOTES);
        $tokens = $this->kfmt($ctx['tokens']);
        $window = $this->kfmt($ctx['window']);

        // The permission mode the composer chip shows and its menu marks as current, from settings.
        $modeLabels = ['ask' => 'Ask before changing', 'acknowledge' => 'Compatibility', 'auto' => 'Continue automatically'];
        $settings = $this->data?->settings() ?? [];
        $modeKey = \is_string($settings['mode'] ?? null) && isset($modeLabels[$settings['mode']]) ? (string) $settings['mode'] : 'ask';
        $modeLabel = $modeLabels[$modeKey];
        $modeMenu = '';
        foreach ($modeLabels as $key => $label) {
            $modeMenu .= sprintf(
                '<button type="button" role="menuitem" class="milpa-mode-opt mui-btn mui-btn--ghost mui-btn--sm mui-btn--full" data-mode="%s" data-label="%s"%s style="justify-content:flex-start;text-align:start">%s<span class="mui-badge" style="margin-inline-start:auto">%s</span></button>',
                $key,
                htmlspecialchars($label, ENT_QUOTES),
                $key === $modeKey ? ' aria-current="true"' : '',
                htmlspecialchars($label, ENT_QUOTES),
                $key,
            );
        }
        $free = $this->kfmt($ctx['free']);
        $pct = $ctx['used_pct'];
        $barColor = $pct < 70 ? 'var(--success)' : ($pct < 90 ? 'var(--warning)' : 'var(--danger)');

        return <<<HTML
<div class="composer-wrap" style="position:relative;margin-top:var(--space-2)">
  <div class="composer-panels" style="position:absolute;right:0;bottom:calc(100% + var(--space-3));display:flex;gap:var(--space-4);align-items:flex-end">

    <div class="composer-panel" data-panel-for="session" hidden style="width:260px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface-raised);box-shadow:var(--shadow-lg);padding:var(--space-5);display:flex;flex-direction:column;gap:var(--space-4);font-family:var(--font-mono)">
      <p style="margin:0;font-size:var(--text-sm)">Session <span style="color:var(--text-muted)">· {$c['turns']} turns</span></p>
      <div style="height:1px;background:var(--border-subtle)"></div>
      <p style="margin:0;display:flex;justify-content:space-between;font-size:var(--text-2xs)"><span style="color:var(--text-secondary)">Steps</span><span>{$c['steps']}</span></p>
      <p style="margin:0;display:flex;justify-content:space-between;font-size:var(--text-2xs)"><span style="color:var(--text-secondary)">Tool calls</span><span>{$c['tool_calls']}</span></p>
      <p style="margin:0;display:flex;justify-content:space-between;font-size:var(--text-2xs)"><span style="color:var(--text-secondary)">State</span><span style="color:var(--accent-text)">{$c['state']}</span></p>
    </div>

    <div class="composer-panel" data-panel-for="context" hidden style="width:300px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface-raised);box-shadow:var(--shadow-lg);padding:var(--space-5);display:flex;flex-direction:column;gap:var(--space-4);font-family:var(--font-mono)">
      <p style="margin:0;font-size:var(--text-sm)">Context <span style="color:var(--text-muted)">· {$tokens} / {$window}</span></p>
      <div class="mui-progress" role="progressbar" aria-valuenow="{$pct}" aria-valuemin="0" aria-valuemax="100" style="width:100%"><span class="mui-progress__bar" style="width:{$pct}%;background:{$barColor}"></span></div>
      <p style="margin:0;display:flex;justify-content:space-between;font-size:var(--text-2xs);color:var(--text-secondary)"><span>{$pct}% used</span><span>{$free} free</span></p>
    </div>

  </div>

  <div class="milpa-composer-box" style="border:1px solid var(--border);border-radius:22px;background:var(--surface-raised);box-shadow:var(--shadow-md);padding:var(--space-4) var(--space-5) var(--space-3)">
    <textarea id="composer-input" class="mui-textarea" rows="2" placeholder="Write to the session…" style="border:0;outline:0;background:transparent;min-height:3rem;padding:0;font-size:var(--text-sm);font-family:var(--font-mono);width:100%;resize:none"></textarea>
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-top:var(--space-2)">
      <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm mui-btn--icon" aria-label="attach" style="border-radius:var(--radius-full)">＋</button>
      <span style="position:relative;display:inline-flex">
        <button type="button" class="mui-badge" id="milpa-mode-chip" aria-haspopup="true" aria-expanded="false" style="cursor:pointer;border:1px solid var(--border);font:inherit;display:inline-flex;align-items:center;gap:6px"><span id="milpa-mode-label">{$modeLabel}</span><span aria-hidden="true" style="opacity:.6">▾</span></button>
        <div id="milpa-mode-menu" hidden role="menu" style="position:absolute;bottom:calc(100% + 8px);left:0;z-index:60;min-width:15rem;background:var(--surface-raised);border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-lg);padding:4px">{$modeMenu}</div>
      </span>
      <span style="margin-inline-start:auto;display:flex;align-items:center;gap:var(--space-2);font-family:var(--font-mono);font-size:var(--text-2xs)">
        <button type="button" class="composer-chip" data-open-panel="session" style="border:1px solid var(--border);border-radius:var(--radius-full);background:var(--surface);color:var(--text);padding:4px 10px;cursor:pointer;font:inherit">◈ {$c['turns']} turns · {$c['tool_calls']} tools</button>
        <button type="button" class="composer-chip" data-open-panel="context" style="border:1px solid var(--border);border-radius:var(--radius-full);background:var(--surface);color:var(--text);padding:4px 10px;cursor:pointer;font:inherit">▤ {$tokens}/{$window}</button>
        <button type="button" class="mui-btn mui-btn--primary mui-btn--icon" id="milpa-send" aria-label="continue session" disabled style="border-radius:var(--radius-full)">↑</button>
      </span>
    </div>
  </div>
  <p style="margin:var(--space-2) 0 0;font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">Model: {$model} · panels open on their figures, close as you type.</p>
</div>
HTML;
    }

    /** The real model label for the Auth provider option: "Local model · <model> (<endpoint>)". */
    private function authModelLabel(): string
    {
        $m = $this->data?->model() ?? ['model' => 'qwen3.8-27b', 'endpoint' => 'http://llama.local:11438'];

        return htmlspecialchars('Local model · ' . $m['model'] . ' (' . $m['endpoint'] . ')', ENT_QUOTES);
    }

    /** The sidebar session list, from the real session store (greenhouse decisions/0482). */
    private function sessionsList(): string
    {
        $sessions = $this->data?->sessions() ?? [];
        if ($sessions === []) {
            return '<p class="mui-empty" style="padding:0 var(--space-4)">No sessions yet. Open a workspace to start one.</p>';
        }

        $current = $this->data->currentSessionId();
        $out = '';
        foreach ($sessions as $s) {
            $id = (string) $s['id'];
            $active = $id === $current;
            $out .= sprintf(
                '<a class="mui-sidebar__item milpa-session-item" data-session-id="%s" href="?session=%s"%s style="flex-direction:column;align-items:flex-start;gap:4px;height:auto;padding-block:var(--space-3)"><span class="milpa-session-goal" style="font-size:var(--text-sm)">%s</span><span class="mui-badge">%s</span></a>',
                htmlspecialchars($id, ENT_QUOTES),
                rawurlencode($id),
                $active ? ' aria-current="page"' : '',
                htmlspecialchars($s['goal'], ENT_QUOTES),
                htmlspecialchars($s['state'], ENT_QUOTES),
            );
        }

        return $out;
    }

    /** The topbar's session header — the active session's goal, id and mode, or an empty-workspace note. */
    private function topbarHeader(): string
    {
        $id = $this->data?->currentSessionId() ?? '';
        if ($id === '') {
            return '<span style="font-size:var(--text-base);font-weight:var(--weight-medium)">No session open</span>'
                . '<span style="font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">Open a workspace to start one</span>';
        }

        $goal = '(no goal recorded)';
        foreach ($this->data?->sessions() ?? [] as $s) {
            if ((string) $s['id'] === $id) {
                $goal = (string) $s['goal'];

                break;
            }
        }
        $settings = $this->data?->settings() ?? [];
        $mode = \is_string($settings['mode'] ?? null) && $settings['mode'] !== '' ? (string) $settings['mode'] : 'ask';

        return sprintf(
            '<span style="font-size:var(--text-base);font-weight:var(--weight-medium)">%s</span>'
            . '<span style="font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">immutable goal · session %s · %s mode</span>',
            htmlspecialchars($goal, ENT_QUOTES),
            htmlspecialchars($id, ENT_QUOTES),
            htmlspecialchars($mode, ENT_QUOTES),
        );
    }

    /** The topbar's right side: the real session state, the mode, and Export session (autopsy/video material). */
    private function topbarActions(): string
    {
        $state = $this->data?->counters()['state'] ?? 'idle';
        $working = $state === 'working';
        $id = $this->data?->currentSessionId() ?? '';

        $modeLabels = ['ask' => 'Ask before changing', 'acknowledge' => 'Compatibility', 'auto' => 'Continue automatically'];
        $settings = $this->data?->settings() ?? [];
        $mode = \is_string($settings['mode'] ?? null) && isset($modeLabels[$settings['mode']]) ? $modeLabels[$settings['mode']] : 'Ask before changing';

        $href = '/desktop/export' . ($id !== '' ? '?session=' . rawurlencode($id) : '');

        return sprintf(
            '<span class="%s" id="milpa-topstate">%s</span>'
            . '<span class="mui-badge">%s</span>'
            . '<a class="mui-btn mui-btn--sm" id="milpa-export" href="%s" download>Export session</a>',
            $working ? 'mui-badge mui-badge--accent mui-badge--dot' : 'mui-badge',
            htmlspecialchars(ucfirst($state), ENT_QUOTES),
            htmlspecialchars($mode, ENT_QUOTES),
            htmlspecialchars($href, ENT_QUOTES),
        );
    }

    /** The status bar's counters, from the current session (greenhouse decisions/0482). */
    private function statusCounters(): string
    {
        $c = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];

        return sprintf(
            '%d turns · %d steps · %d tokens · %d tool calls',
            $c['turns'],
            $c['steps'],
            $c['tokens'],
            $c['tool_calls'],
        );
    }

    /** The Work board (2e), columns by status, from the current session's work items. */
    private function workBoard(): string
    {
        $work = $this->data?->work() ?? [];
        if ($work === []) {
            return '<div class="mui-empty"><p class="mui-empty__title">No work board yet</p><p class="mui-empty__desc">A session writes its plan as work items; they appear here by status.</p></div>';
        }

        // Reaching here means work() returned items, so $this->data is non-null.
        $session = htmlspecialchars($this->data->currentSessionId(), ENT_QUOTES);
        $columns = ['pending' => 'Pending', 'in_progress' => 'In progress', 'done' => 'Done', 'blocked' => 'Blocked'];
        $byStatus = ['pending' => '', 'in_progress' => '', 'done' => '', 'blocked' => ''];
        foreach ($work as $i => $item) {
            $status = \array_key_exists($item['status'], $columns) ? $item['status'] : 'pending';
            $byStatus[$status] .= sprintf(
                '<article class="mui-card mui-card--compact" draggable="true" data-index="%d" style="cursor:grab"><div class="mui-card__body"><p style="margin:0 0 var(--space-3);font-size:var(--text-sm)">%s</p><span class="mui-badge">%s</span></div></article>',
                $i,
                htmlspecialchars($item['title'], ENT_QUOTES),
                htmlspecialchars($item['origin'], ENT_QUOTES),
            );
        }

        $out = '<div class="work-board" data-session="' . $session . '" style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--space-4);align-items:start">';
        foreach ($columns as $key => $label) {
            $out .= sprintf(
                '<section class="work-col" data-status="%s" style="display:flex;flex-direction:column;gap:var(--space-3);min-height:8rem;padding:var(--space-2);border-radius:var(--radius-md)"><div class="mui-cluster mui-cluster--sm" style="justify-content:space-between"><span class="mui-section__kicker" style="margin:0">%s</span></div>%s</section>',
                htmlspecialchars($key, ENT_QUOTES),
                htmlspecialchars($label, ENT_QUOTES),
                $byStatus[$key],
            );
        }

        return $out . '</div>';
    }

    /** The Audit projection stats (2f), from the current session's real counters. */
    private function projectionStats(): string
    {
        $c = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];
        $stats = [
            'state' => $c['state'], 'turns' => $c['turns'], 'steps' => $c['steps'],
            'tokens' => $c['tokens'], 'tool calls' => $c['tool_calls'],
        ];
        $out = '';
        foreach ($stats as $label => $value) {
            $out .= sprintf(
                '<p class="mui-replay__stat"><span class="mui-replay__stat-label">%s</span><span class="mui-replay__stat-value">%s</span></p>',
                htmlspecialchars($label, ENT_QUOTES),
                htmlspecialchars((string) $value, ENT_QUOTES),
            );
        }

        return $out;
    }

    /** The Audit stream (2f): the session's facts, from the shared event log. */
    private function auditStream(): string
    {
        $audit = $this->data?->audit() ?? [];
        if ($audit === []) {
            return '<li class="mui-replay__event"><span class="mui-replay__actor">no facts recorded yet</span></li>';
        }

        $out = '';
        foreach ($audit as $fact) {
            $out .= sprintf(
                '<li class="mui-replay__event"><span class="mui-replay__type">%s</span> <span class="mui-replay__actor">%s · seq %d</span></li>',
                htmlspecialchars($fact['type'], ENT_QUOTES),
                htmlspecialchars($fact['data'], ENT_QUOTES),
                $fact['seq'],
            );
        }

        return $out;
    }

    /**
     * The client component runtime, always served (greenhouse decisions/0476, 0478).
     *
     * `MilpaShell` bridges the live transport to the UI, and the panel API is the DX: `on('<event>', cb)` /
     * `onAny(cb)` react to events, `panel('<id>')` returns a panel body, `onStatus(cb)` tracks the connection.
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
<html data-theme="dark" lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Milpa Desktop</title>
<link rel="stylesheet" href="/desktop/assets/tokens.css">
<link rel="stylesheet" href="/desktop/assets/bundle.css">
<style>
  body { margin: 0; background: var(--bg); color: var(--text); font-family: var(--font-body); }
  .app { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
  .chrome { flex: none; display: flex; align-items: center; gap: var(--space-3); height: 46px; padding: 0 var(--space-4); border-bottom: 1px solid var(--border-subtle); background: var(--surface); }
  .lights { display: flex; gap: 8px; }
  .lights span { width: 12px; height: 12px; border-radius: 99px; }
  .statusbar { flex: none; display: flex; align-items: center; gap: var(--space-5); height: 40px; padding: 0 var(--space-4); border-top: 1px solid var(--border-subtle); background: var(--surface); font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); }
  /* The `hidden` attribute must win over an element's own inline `display:` — the auth overlay, the
     composer's floating panels and the replay views all carry both, so without !important they can
     never actually hide (the inline display outranks a plain [hidden] rule). This is what makes
     "Open workspace" reveal the dashboard and the composer panels close as you type. */
  [hidden] { display: none !important; }
  .tabpane[hidden] { display: none; }
  ul.feed { list-style: none; margin: 0; padding: 0; font: var(--text-xs)/1.5 var(--font-mono); overflow: auto; }
  ul.feed li { padding: var(--space-2) var(--space-3); border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--border-subtle); margin: var(--space-2) 0; word-break: break-word; }
  .mui-empty { color: var(--text-muted); font-size: var(--text-sm); }
  .panel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: var(--space-4); }
  /* The Grano mark: the 13 kernels form the M, each scaling into place in a staggered sweep (the two
     pillars, then the inner V). The grain is oro-300 constant — the logo is brand, not UI, and does not
     theme (per the kit). Hover replays the forming. */
  .milpa-grainmark .g { transform-box: fill-box; transform-origin: center; animation: milpa-grain-in .5s cubic-bezier(.22,1,.36,1) both; }
  .milpa-grainmark:hover .g { animation: milpa-grain-in .5s cubic-bezier(.22,1,.36,1) both; }
  @keyframes milpa-grain-in { from { opacity: 0; transform: scale(0); } to { opacity: 1; transform: scale(1); } }
  .milpa-search-hit { display: none !important; }
  .milpa-mode-opt[aria-current="true"] { background: var(--accent-subtle); color: var(--accent-text); }
  /* The focus ring belongs to the composer BOX, not the bare textarea — so the accent border sits out at
     the rounded container with its padding as breathing room, instead of hugging the typed text. */
  .milpa-composer-box:focus-within { border-color: var(--accent) !important; box-shadow: 0 0 0 3px var(--accent-subtle); }
  /* No visible scrollbars anywhere — scrolling still works. */
  * { scrollbar-width: none; -ms-overflow-style: none; }
  *::-webkit-scrollbar { width: 0; height: 0; display: none; }
  /* The message stream: one visual language, a distinct voice per kind. New messages arrive at the bottom
     and the composer is docked below (sticky), so the thread reads top→down and the box never moves. */
  #milpa-chat { display: flex; flex-direction: column; gap: var(--space-5); max-width: 88ch; }
  .msg__meta { font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); display: block; }
  .msg--user { display: flex; justify-content: flex-end; }
  .msg--user > div { max-width: 56ch; padding: var(--space-3) var(--space-5); border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface); }
  .msg--agent > p { margin: var(--space-2) 0 0; font-size: var(--text-sm); line-height: var(--leading-relaxed); text-wrap: pretty; }
  /* Thinking: the agent reasoning aloud — dimmed and italic, clearly not final speech. */
  .msg--thinking { color: var(--text-muted); font-style: italic; }
  .msg--thinking > p { margin: var(--space-1) 0 0; font-size: var(--text-xs); line-height: var(--leading-relaxed); white-space: pre-wrap; }
  /* Tool call: a compact mono card, the machinery made legible. */
  .msg--tool > div { display: inline-flex; align-items: baseline; gap: var(--space-2); padding: var(--space-2) var(--space-3); border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--border-subtle); font-family: var(--font-mono); font-size: var(--text-xs); }
  .msg--tool .msg__tool-name { color: var(--accent-text); }
  /* System: a centered, quiet notice — the house speaking, not a participant. */
  .msg--system { align-self: center; text-align: center; font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); letter-spacing: .04em; text-transform: uppercase; }
  /* Task: a row the agent added to the plan — a leading mark, monospace title. */
  .msg--task > div { display: flex; align-items: baseline; gap: var(--space-3); padding: var(--space-2) var(--space-3); border-radius: var(--radius-sm); background: var(--accent-subtle); }
  .msg--task .msg__mark { color: var(--accent-text); font-weight: var(--weight-bold); }
  .msg--task .msg__title { font-size: var(--text-sm); }
  @media (prefers-reduced-motion: reduce) { .milpa-grainmark .g { animation: none !important; opacity: 1; } * { animation-duration: .001ms !important; transition-duration: .001ms !important; } }
</style>
</head>
<body>
<!--RUNTIME-->
<div class="app">

  <div class="chrome">
    <span class="lights"><span style="background:var(--danger)"></span><span style="background:var(--warning)"></span><span style="background:var(--success)"></span></span>
    <input id="milpa-search" type="search" placeholder="Search sessions…" aria-label="Search sessions" autocomplete="off" class="mui-input mui-input--sm" style="max-width:280px;margin-inline-start:var(--space-4);font-family:var(--font-mono);font-size:var(--text-xs)">
    <span style="margin-inline:auto;font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">served by Milpa · one origin</span>
    <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm" id="milpa-auth-open">Open workspace</button>
    <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm" id="milpa-theme" aria-label="toggle theme">◐ Theme</button>
  </div>

  <div class="mui-shell" style="flex:1;min-height:0;--_sidebar-w:17.5rem;overflow:hidden;display:grid;grid-template-columns:var(--_sidebar-w) minmax(0,1fr);grid-template-rows:auto minmax(0,1fr)">
    <nav class="mui-sidebar" aria-label="main" style="grid-row:1 / span 2;grid-column:1;position:static;height:auto;min-height:0">
      <span class="mui-sidebar__brand" style="display:inline-flex;align-items:center;gap:10px;cursor:default"><svg class="milpa-grainmark" viewBox="0 0 60 60" width="26" height="26" role="img" aria-label="Milpa" style="flex:none"><g fill="#E8B14C"><rect class="g" x="0" y="0" width="10" height="10" rx="2.5" style="animation-delay:0s"/><rect class="g" x="0" y="12.5" width="10" height="10" rx="2.5" style="animation-delay:.045s"/><rect class="g" x="0" y="25" width="10" height="10" rx="2.5" style="animation-delay:.09s"/><rect class="g" x="0" y="37.5" width="10" height="10" rx="2.5" style="animation-delay:.135s"/><rect class="g" x="0" y="50" width="10" height="10" rx="2.5" style="animation-delay:.18s"/><rect class="g" x="50" y="0" width="10" height="10" rx="2.5" style="animation-delay:.225s"/><rect class="g" x="50" y="12.5" width="10" height="10" rx="2.5" style="animation-delay:.27s"/><rect class="g" x="50" y="25" width="10" height="10" rx="2.5" style="animation-delay:.315s"/><rect class="g" x="50" y="37.5" width="10" height="10" rx="2.5" style="animation-delay:.36s"/><rect class="g" x="50" y="50" width="10" height="10" rx="2.5" style="animation-delay:.405s"/><rect class="g" x="12.5" y="12.5" width="10" height="10" rx="2.5" style="animation-delay:.45s"/><rect class="g" x="37.5" y="12.5" width="10" height="10" rx="2.5" style="animation-delay:.495s"/><rect class="g" x="25" y="25" width="10" height="10" rx="2.5" style="animation-delay:.54s"/></g></svg><span class="mui-sidebar__wordmark">Milpa</span></span>
      <div class="mui-sidebar__nav">
        <div class="mui-sidebar__section">
          <a class="mui-sidebar__item" href="#" data-nav="sessions" aria-current="page"><span class="mui-sidebar__item-icon">▤</span><span class="mui-sidebar__item-label">Sessions</span></a>
          <a class="mui-sidebar__item" href="#" data-nav="decisions"><span class="mui-sidebar__item-icon">◈</span><span class="mui-sidebar__item-label">Decisions</span><span class="mui-sidebar__item-badge mui-badge mui-badge--warning" id="milpa-decisions-badge" hidden>1</span></a>
          <a class="mui-sidebar__item" href="#" data-nav="capabilities"><span class="mui-sidebar__item-icon">▩</span><span class="mui-sidebar__item-label">Capabilities</span></a>
          <a class="mui-sidebar__item" href="#" data-nav="settings"><span class="mui-sidebar__item-icon">⚙</span><span class="mui-sidebar__item-label">Settings</span></a>
        </div>
        <div class="mui-sidebar__section" id="milpa-sessions">
          <span class="mui-sidebar__section-label">sessions · goal and state</span>
          <!--SESSIONS-->
        </div>
      </div>
      <div class="mui-sidebar__footer" style="display:flex;flex-direction:column;gap:var(--space-2)">
        <button type="button" class="mui-btn mui-btn--subtle mui-btn--full" id="milpa-new-session">New session</button>
        <a class="mui-btn mui-btn--ghost mui-btn--sm mui-btn--full" id="milpa-enroll-link" href="/webauthn/enroll">Register a passkey</a>
      </div>
    </nav>

    <header class="mui-topbar" style="grid-row:1;grid-column:2;min-height:64px">
      <div class="mui-topbar__start" style="flex-direction:column;align-items:flex-start;gap:2px"><!--TOPHEADER-->
      </div>
      <div class="mui-topbar__end"><!--TOPBARACTIONS--></div>
    </header>

    <main class="mui-shell__main mui-shell__main--wide" style="grid-row:2;grid-column:2;min-height:0;padding:0;display:flex;flex-direction:column;overflow:hidden">
      <div class="view" data-view="session" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden">
      <div class="mui-tabs" role="tablist" style="padding:0 var(--space-6);flex:none">
        <button class="mui-tabs__tab" role="tab" aria-selected="true" type="button" data-tab="chat">Conversation</button>
        <button class="mui-tabs__tab" role="tab" aria-selected="false" type="button" data-tab="work">Work</button>
        <button class="mui-tabs__tab" role="tab" aria-selected="false" type="button" data-tab="activity">Activity</button>
        <button class="mui-tabs__tab" role="tab" aria-selected="false" type="button" data-tab="context">Context</button>
      </div>

      <div style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8)">

        <section class="tabpane" data-pane="chat" id="milpa-chat">
          <div class="msg msg--system">session opened · nothing runs on open</div>
          <div class="msg msg--user"><div><span class="msg__meta">you · now</span><p style="margin:var(--space-2) 0 0;font-size:var(--text-sm)">Enable the devtools capability on this app.</p></div></div>
          <div class="msg msg--thinking"><span class="msg__meta">agent · thinking</span><p>Reading the app's capabilities and the parked gate before acting…</p></div>
          <div class="msg msg--tool"><div><span class="msg__tool-name">capabilities.list</span><span>→ 6 capabilities</span></div></div>
          <div class="msg msg--agent"><span class="msg__meta">agent · local</span><p>When an agent needs your decision, it parks a gate here — a durable question, not a modal. Approve it with your passkey, in this origin.</p></div>
          <div class="msg msg--task"><div><span class="msg__mark">+</span><span class="msg__title">Enable devtools capability</span><span class="mui-badge" style="margin-inline-start:auto">todo</span></div></div>

          <!-- The consent gate: the design's mui-gate, rendered live when an agent parks one. -->
          <div class="mui-card mui-card--raised" id="milpa-gate" hidden style="border-color:var(--warning-border);background:var(--warning-bg)">
            <div class="mui-card__body mui-gate">
              <div class="mui-gate__request">
                <p class="mui-gate__actor" style="margin:0">an agent stopped its turn · a durable question, not a modal</p>
                <p class="mui-gate__action" style="margin:var(--space-2) 0;font-size:var(--text-base)" data-gate-action>An agent is asking to act.</p>
                <p class="mui-gate__facts" style="margin:0">operation <strong data-gate-op></strong> · arguments <code data-gate-args></code></p>
              </div>
              <div class="mui-gate__decisions">
                <a class="mui-btn mui-btn--primary" data-gate-approve href="#">Approve with passkey</a>
                <button type="button" class="mui-btn" data-gate-dismiss>Dismiss</button>
              </div>
              <p style="margin:0;font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">Answering keeps your answer; it does not resume the session. Continuing is another verb.</p>
            </div>
          </div>
        </section>

        <section class="tabpane" data-pane="work" hidden>
          <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">The session's work board — todo items by status.</p>
          <!--WORK-->
        </section>

        <section class="tabpane" data-pane="activity" hidden style="display:grid;grid-template-columns:1fr 20rem;gap:var(--space-6);align-items:start">
          <div>
            <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">A projection of the session's facts — not a full audit log. Live over the hub.</p>
            <ol class="mui-replay__stream" id="milpa-activity" aria-live="polite"><!--AUDIT--></ol>
          </div>
          <aside class="mui-replay__projection"><!--PROJECTION--></aside>
        </section>

        <section class="tabpane" data-pane="context" hidden>
          <div class="panel-grid">
            <!-- Panels other plugins contribute through desktop.shell.compose (addPanel) are rendered here. -->
            <!--PANELS-->
          </div>
        </section>

      </div>
      <!-- The composer is docked below the scroll, sticky at the bottom: messages flow above it and it
           stays put. Shown only on the Conversation tab. -->
      <div id="milpa-composer-dock" style="flex:none;padding:var(--space-3) var(--space-8) var(--space-5);border-top:1px solid var(--border-subtle);background:var(--bg)"><!--COMPOSER--></div>
      </div><!-- /view session -->

      <div class="view" data-view="settings" hidden style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8);display:flex;flex-direction:column;gap:var(--space-5)">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-5);align-items:start">

          <div class="mui-card mui-card--raised">
            <div class="mui-card__header"><h2 class="mui-card__title">Model and provider</h2></div>
            <div class="mui-card__body mui-stack">
              <div class="mui-field"><label class="mui-field__label" for="set-prov">Provider</label><span class="mui-select-wrap"><select id="set-prov" class="mui-select"><option>Local model</option><option>Local-network model</option><option>External provider</option></select></span></div>
              <div class="mui-field"><label class="mui-field__label" for="set-end">Endpoint</label><input id="set-end" class="mui-input" style="font-family:var(--font-mono);font-size:var(--text-xs)" value="<!--ENDPOINT-->"><span class="mui-field__hint">The endpoint receives context. It does not execute operations.</span></div>
              <div class="mui-field mui-field--row" style="justify-content:space-between"><label class="mui-field__label" for="set-stream">Show streaming tokens</label><input class="mui-switch" type="checkbox" id="set-stream" checked="checked"></div>
            </div>
          </div>

          <div class="mui-card mui-card--raised">
            <div class="mui-card__header"><h2 class="mui-card__title">Default autonomy</h2></div>
            <div class="mui-card__body mui-stack mui-stack--sm">
              <label class="mui-choice"><input class="mui-radio" type="radio" name="set-mode" value="ask" checked="checked"><span class="mui-choice__text">Ask before changing <span class="mui-badge" style="margin-inline-start:8px">ask</span><span class="mui-choice__hint">Pauses mutations without a standing permission.</span></span></label>
              <label class="mui-choice"><input class="mui-radio" type="radio" name="set-mode" value="acknowledge"><span class="mui-choice__text">Compatibility <span class="mui-badge" style="margin-inline-start:8px">acknowledge</span><span class="mui-choice__hint">Today decides like auto: no observable prior notice.</span></span></label>
              <label class="mui-choice"><input class="mui-radio" type="radio" name="set-mode" value="auto"><span class="mui-choice__text">Continue automatically <span class="mui-badge" style="margin-inline-start:8px">auto</span><span class="mui-choice__hint">Signatures and incomplete intent still stop.</span></span></label>
              <div class="mui-alert mui-alert--info" role="note"><span class="mui-alert__icon" aria-hidden="true">i</span><div class="mui-alert__content"><p class="mui-alert__desc">The three values are not yet three behaviorally distinct levels.</p></div></div>
            </div>
          </div>

          <div class="mui-card">
            <div class="mui-card__header"><h2 class="mui-card__title">Context and storage</h2></div>
            <div class="mui-card__body mui-stack mui-stack--sm">
              <div class="mui-field mui-field--row" style="justify-content:space-between"><label class="mui-field__label" for="set-comp">Compact context automatically</label><input class="mui-switch" id="set-comp" type="checkbox" checked="checked"></div>
              <p style="margin:0;font-family:var(--font-mono);font-size:var(--text-2xs);color:var(--text-muted)">Compaction reduces what the model sees, never the session record.</p>
              <div class="mui-field"><label class="mui-field__label" for="set-path">Sessions folder</label><input id="set-path" class="mui-input mui-input--sm" style="font-family:var(--font-mono);font-size:var(--text-xs)" value=".milpa/sessions/" readonly="readonly"></div>
            </div>
          </div>

          <div class="mui-card">
            <div class="mui-card__header"><h2 class="mui-card__title">Appearance</h2></div>
            <div class="mui-card__body mui-stack mui-stack--sm">
              <div class="mui-field"><span class="mui-field__label">Theme</span><div class="mui-cluster mui-cluster--sm"><button type="button" class="mui-btn mui-btn--sm" data-theme-set="system">System</button><button type="button" class="mui-btn mui-btn--sm" data-theme-set="dark" aria-pressed="true">Dark</button><button type="button" class="mui-btn mui-btn--sm" data-theme-set="light">Light</button></div></div>
              <div class="mui-field"><span class="mui-field__label">Interface scale</span><div class="mui-cluster mui-cluster--sm"><button type="button" class="mui-btn mui-btn--sm" aria-pressed="true">100%</button><button type="button" class="mui-btn mui-btn--sm">115%</button><button type="button" class="mui-btn mui-btn--sm">130%</button></div></div>
            </div>
          </div>

        </div>
        <div class="mui-cluster" style="margin-top:auto;justify-content:flex-end;flex:none;align-items:center"><span id="milpa-settings-saved" class="mui-badge mui-badge--success" hidden>Saved</span><button type="button" class="mui-btn" id="milpa-discard">Discard changes</button><button type="button" class="mui-btn mui-btn--primary" id="milpa-save-settings">Save settings</button></div>
      </div>

      <div class="view" data-view="capabilities" hidden style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8)">
        <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">Capabilities installed in this app — read from the runtime.</p>
        <div class="mui-table-wrap">
          <table class="mui-table">
            <thead><tr><th scope="col">Capability</th><th scope="col">Version</th><th scope="col">Type</th><th scope="col">Author</th></tr></thead>
            <tbody id="milpa-capabilities"><!--CAPABILITIES--></tbody>
          </table>
        </div>
      </div>

      <div class="view" data-view="decisions" hidden style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8)">
        <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">Decisions an agent has parked for you — durable questions, not modals. Each is approved with your passkey, in this origin.</p>
        <ol class="mui-replay__stream" id="milpa-decisions-list" aria-live="polite"></ol>
        <p class="mui-empty" id="milpa-decisions-empty" style="color:var(--text-muted)">No decisions to make. When an agent parks a gate, it appears here for you to approve or refuse.</p>
      </div>

    </main>
  </div>

  <div class="statusbar">
    <span id="milpa-conn" style="color:var(--text-muted)">○ connecting…</span>
    <span>qwen3.8-27b · local model</span>
    <span><!--STATUS--></span>
    <span style="margin-inline-start:auto">m4-core local-agent · v0.1.0</span>
  </div>
</div>

<!-- Auth (wireframe 2a): open the workspace. An entry overlay; nothing runs on open. -->
<div class="view" data-view="auth" id="milpa-auth" hidden style="position:fixed;inset:0;z-index:1400;display:grid;grid-template-columns:1fr 560px;background:var(--bg)">
  <div style="display:flex;flex-direction:column;justify-content:flex-end;padding:var(--space-12);border-inline-end:1px solid var(--border-subtle);background:var(--surface)">
    <p class="mui-section__kicker" style="margin:0 0 var(--space-3)">local workspace</p>
    <h1 style="font-family:var(--font-heading);font-size:var(--text-4xl);line-height:1.03;margin:0 0 var(--space-4)">Milpa Desktop</h1>
    <p style="margin:0;max-width:36ch;font-size:var(--text-base);line-height:var(--leading-relaxed);color:var(--text-secondary)">Open a Milpa app to start, understand and resume an agent's work. The session is the unit; nothing runs on open.</p>
  </div>
  <div style="display:flex;flex-direction:column;gap:var(--space-6);padding:var(--space-10) var(--space-8);overflow:auto">
    <div class="mui-stack">
      <div class="mui-field"><label class="mui-field__label" for="auth-app">Milpa app</label><input id="auth-app" class="mui-input mui-input--lg" style="font-family:var(--font-mono)" value="getmilpa/framework" readonly="readonly"><span class="mui-field__hint">Reads <code>.milpa/foundation.json</code>. One app at a time.</span></div>
      <div class="mui-field"><span class="mui-field__label">Decision identity</span>
        <label class="mui-choice"><input class="mui-radio" type="radio" name="auth-id" checked="checked"><span class="mui-choice__text">System user<span class="mui-choice__hint">Not verified. Call signatures are asked for separately.</span></span></label>
        <label class="mui-choice"><input class="mui-radio" type="radio" name="auth-id"><span class="mui-choice__text">Signature-verified principal<span class="mui-choice__hint">Requires an external mechanism.</span></span></label>
      </div>
      <div class="mui-field"><label class="mui-field__label" for="auth-prov">Model provider</label><span class="mui-select-wrap"><select id="auth-prov" class="mui-select mui-select--lg"><option><!--AUTHMODEL--></option><option>Local-network model</option><option>External provider</option></select></span></div>
    </div>
    <div class="mui-alert mui-alert--warning" role="note"><span class="mui-alert__icon" aria-hidden="true">!</span><div class="mui-alert__content"><p class="mui-alert__title">Your system user is not a verified identity</p><p class="mui-alert__desc">Authorizing in a session grants the operation; it is not signing the call.</p></div></div>
    <div style="margin-top:auto"><button type="button" class="mui-btn mui-btn--primary mui-btn--full mui-btn--lg" id="milpa-auth-enter">Open workspace</button></div>
  </div>
</div>

<script>
  (function () {
    // Auth overlay (open the workspace) — creating a session PERSISTS it, then reload shows it (0483).
    var auth = document.getElementById('milpa-auth');
    document.getElementById('milpa-auth-open').addEventListener('click', function () { auth.hidden = false; });
    document.getElementById('milpa-auth-enter').addEventListener('click', function () {
      var app = document.getElementById('auth-app');
      fetch('/desktop/sessions', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ goal: 'New session · ' + (app ? app.value : '') })
      }).then(function () { location.reload(); });
    });

    // Settings persistence: Save posts the form, Discard reloads the persisted values.
    var saveBtn = document.getElementById('milpa-save-settings');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        var end = document.getElementById('set-end'), stream = document.getElementById('set-stream');
        var comp = document.getElementById('set-comp'), mode = document.querySelector('input[name="set-mode"]:checked');
        fetch('/desktop/settings', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            endpoint: end ? end.value : '', stream: stream ? stream.checked : true,
            compact: comp ? comp.checked : true, mode: mode ? mode.value : 'ask'
          })
        }).then(function () {
          var badge = document.getElementById('milpa-settings-saved');
          if (badge) { badge.hidden = false; setTimeout(function () { badge.hidden = true; }, 2000); }
        });
      });
    }
    var discardBtn = document.getElementById('milpa-discard');
    if (discardBtn) { discardBtn.addEventListener('click', function () { location.reload(); }); }

    // Composer floating panels (wireframe 3a): open on their figures, close as you type.
    var composerPanels = document.querySelectorAll('.composer-panel');
    document.querySelectorAll('.composer-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var target = chip.getAttribute('data-open-panel');
        composerPanels.forEach(function (p) {
          p.hidden = p.getAttribute('data-panel-for') === target ? !p.hidden : true;
        });
      });
    });
    var composerInput = document.getElementById('composer-input');
    var sendBtn = document.getElementById('milpa-send');
    var chat = document.getElementById('milpa-chat');
    // The send button is enabled only when there is something to send (text today; attachments later).
    function refreshSend() {
      if (sendBtn) { sendBtn.disabled = !composerInput || composerInput.value.trim() === ''; }
    }
    if (composerInput) {
      composerInput.addEventListener('input', function () {
        composerPanels.forEach(function (p) { p.hidden = true; });
        refreshSend();
      });
    }
    // One renderer, a distinct voice per kind. The backend's stream routes into this by event type; the
    // user's own message uses it too. (Persisting and running the turn is the agent runtime, decisions/0254.)
    function appendMessage(kind, opts) {
      if (!chat) { return; }
      opts = opts || {};
      var el = document.createElement('div');
      el.className = 'msg msg--' + kind;
      function meta(t) { var s = document.createElement('span'); s.className = 'msg__meta'; s.textContent = t; return s; }
      function para(t, cls) { var p = document.createElement('p'); if (cls) { p.className = cls; } p.textContent = t; return p; }
      if (kind === 'user') {
        var box = document.createElement('div');
        box.appendChild(meta('you · now'));
        var p = para(opts.text || ''); p.style.cssText = 'margin:var(--space-2) 0 0;font-size:var(--text-sm);white-space:pre-wrap';
        box.appendChild(p); el.appendChild(box);
      } else if (kind === 'agent' || kind === 'thinking') {
        el.appendChild(meta(kind === 'thinking' ? 'agent · thinking' : 'agent · local'));
        el.appendChild(para(opts.text || ''));
      } else if (kind === 'tool') {
        var d = document.createElement('div');
        var n = document.createElement('span'); n.className = 'msg__tool-name'; n.textContent = opts.name || 'tool';
        var r = document.createElement('span'); r.textContent = '→ ' + (opts.result || '');
        d.appendChild(n); d.appendChild(r); el.appendChild(d);
      } else if (kind === 'task') {
        var td = document.createElement('div');
        var mk = document.createElement('span'); mk.className = 'msg__mark'; mk.textContent = '+';
        var ti = document.createElement('span'); ti.className = 'msg__title'; ti.textContent = opts.title || '';
        var bd = document.createElement('span'); bd.className = 'mui-badge'; bd.style.marginInlineStart = 'auto'; bd.textContent = opts.status || 'todo';
        td.appendChild(mk); td.appendChild(ti); td.appendChild(bd); el.appendChild(td);
      } else { // system
        el.textContent = opts.text || '';
      }
      chat.appendChild(el);
      el.scrollIntoView({ block: 'end' });
      return el;
    }
    function send() {
      if (!composerInput) { return; }
      var text = composerInput.value.trim();
      if (text === '') { return; }
      appendMessage('user', { text: text });
      composerInput.value = '';
      refreshSend();
      composerInput.focus();
    }
    // While the agent works, the send button becomes Stop; the topbar state follows. "Working" is the
    // backend's to declare (it arrives as a `session.state` event) — the Desktop reflects and signals, it
    // does not run the turn. Stop signals an interrupt; honoring it is the agent runtime's (decisions/0254).
    var working = false;
    function setWorking(on) {
      working = !!on;
      if (sendBtn) {
        sendBtn.textContent = working ? '■' : '↑';
        sendBtn.setAttribute('aria-label', working ? 'stop the turn' : 'continue session');
        sendBtn.disabled = working ? false : (!composerInput || composerInput.value.trim() === '');
      }
      var top = document.getElementById('milpa-topstate');
      if (top) {
        top.textContent = working ? 'Working' : 'Idle';
        top.className = working ? 'mui-badge mui-badge--accent mui-badge--dot' : 'mui-badge';
      }
    }
    if (sendBtn) {
      sendBtn.addEventListener('click', function () {
        if (working) { setWorking(false); appendMessage('system', { text: 'stop requested' }); return; }
        send();
      });
      setWorking(document.getElementById('milpa-topstate') && document.getElementById('milpa-topstate').textContent.trim() === 'Working');
    }
    if (composerInput) {
      // Enter sends; Shift+Enter keeps the newline.
      composerInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !working) { e.preventDefault(); send(); }
      });
    }

    // Work board drag-drop: moving a card to another column PERSISTS its new status (0484).
    var board = document.querySelector('.work-board');
    if (board) {
      var dragged = null;
      board.querySelectorAll('article[draggable]').forEach(function (card) {
        card.addEventListener('dragstart', function () { dragged = card; card.style.opacity = '.5'; });
        card.addEventListener('dragend', function () { card.style.opacity = ''; });
      });
      board.querySelectorAll('.work-col').forEach(function (col) {
        col.addEventListener('dragover', function (e) { e.preventDefault(); col.style.background = 'var(--accent-subtle)'; });
        col.addEventListener('dragleave', function () { col.style.background = ''; });
        col.addEventListener('drop', function (e) {
          e.preventDefault();
          col.style.background = '';
          if (!dragged) { return; }
          col.appendChild(dragged);
          fetch('/desktop/work', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              session: board.getAttribute('data-session'),
              index: parseInt(dragged.getAttribute('data-index'), 10),
              status: col.getAttribute('data-status')
            })
          });
          dragged = null;
        });
      });
    }

    // Theme toggle (dark-first; the design system reads data-theme on <html>).
    document.getElementById('milpa-theme').addEventListener('click', function () {
      var html = document.documentElement;
      html.setAttribute('data-theme', html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });

    // Tabs.
    var tabs = document.querySelectorAll('.mui-tabs__tab');
    var dock = document.getElementById('milpa-composer-dock');
    // The composer belongs to the conversation only — dock it there, hide it on the other tabs.
    function syncDock(name) { if (dock) { dock.hidden = name !== 'chat'; } }
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.setAttribute('aria-selected', String(t === tab)); });
        var name = tab.getAttribute('data-tab');
        document.querySelectorAll('.tabpane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
        syncDock(name);
      });
    });
    function showTab(name) {
      tabs.forEach(function (t) { t.setAttribute('aria-selected', String(t.getAttribute('data-tab') === name)); });
      document.querySelectorAll('.tabpane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
      syncDock(name);
    }

    // Sidebar navigation: swap the whole main between the session view and settings — same shell, not a window.
    var navItems = document.querySelectorAll('.mui-sidebar__item[data-nav]');
    var navToView = { sessions: 'session', decisions: 'decisions', capabilities: 'capabilities', settings: 'settings' };
    function showView(view) {
      // Swap the whole main between its views — same shell, not a window. The auth overlay is a `.view`
      // too but is opened on demand, so it is never toggled by navigation.
      document.querySelectorAll('.view').forEach(function (v) {
        if (v.getAttribute('data-view') !== 'auth') { v.hidden = v.getAttribute('data-view') !== view; }
      });
      navItems.forEach(function (n) {
        var on = navToView[n.getAttribute('data-nav')] === view;
        if (on) { n.setAttribute('aria-current', 'page'); } else { n.removeAttribute('aria-current'); }
      });
    }
    navItems.forEach(function (n) {
      n.addEventListener('click', function (e) {
        e.preventDefault();
        showView(navToView[n.getAttribute('data-nav')] || 'session');
      });
    });

    // New session: open the entry overlay to configure and confirm a new session.
    var newBtn = document.getElementById('milpa-new-session');
    if (newBtn) { newBtn.addEventListener('click', function () { auth.hidden = false; }); }

    // Search: filter the sidebar session list by goal text (client-side over the rendered list).
    var search = document.getElementById('milpa-search');
    if (search) {
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        document.querySelectorAll('.milpa-session-item').forEach(function (item) {
          var goalEl = item.querySelector('.milpa-session-goal');
          var goal = goalEl ? goalEl.textContent.toLowerCase() : '';
          item.classList.toggle('milpa-search-hit', q !== '' && goal.indexOf(q) === -1);
        });
      });
    }

    // Composer mode chip: open a menu to switch ask / acknowledge / auto quickly; the choice persists
    // (a partial settings post that merges — greenhouse decisions/0483).
    var modeChip = document.getElementById('milpa-mode-chip');
    var modeMenu = document.getElementById('milpa-mode-menu');
    if (modeChip && modeMenu) {
      modeChip.addEventListener('click', function (e) {
        e.stopPropagation();
        var willOpen = modeMenu.hidden;
        modeMenu.hidden = !willOpen;
        modeChip.setAttribute('aria-expanded', String(willOpen));
      });
      modeMenu.addEventListener('click', function (e) { e.stopPropagation(); });
      document.addEventListener('click', function () {
        modeMenu.hidden = true;
        modeChip.setAttribute('aria-expanded', 'false');
      });
      modeMenu.querySelectorAll('.milpa-mode-opt').forEach(function (opt) {
        opt.addEventListener('click', function () {
          document.getElementById('milpa-mode-label').textContent = opt.getAttribute('data-label');
          modeMenu.querySelectorAll('.milpa-mode-opt').forEach(function (o) { o.removeAttribute('aria-current'); });
          opt.setAttribute('aria-current', 'true');
          modeMenu.hidden = true;
          modeChip.setAttribute('aria-expanded', 'false');
          fetch('/desktop/settings', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: opt.getAttribute('data-mode') })
          });
        });
      });
    }

    // Register a passkey: only navigate if this app actually mounts the passkey door; otherwise say so
    // in place instead of replacing the whole app with a 404 (the door is the app's to configure).
    var enroll = document.getElementById('milpa-enroll-link');
    if (enroll) {
      enroll.addEventListener('click', function (e) {
        e.preventDefault();
        fetch('/webauthn/enroll', { method: 'GET' }).then(function (r) {
          if (r.ok) { location.href = '/webauthn/enroll'; return; }
          enroll.textContent = 'No passkey door in this app';
          enroll.setAttribute('aria-disabled', 'true');
          enroll.style.opacity = '.6';
        }).catch(function () {
          enroll.textContent = 'No passkey door in this app';
          enroll.style.opacity = '.6';
        });
      });
    }

    // Appearance theme buttons in Settings (dark / light / system).
    document.querySelectorAll('[data-theme-set]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var choice = btn.getAttribute('data-theme-set');
        var theme = choice === 'system'
          ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
          : choice;
        document.documentElement.setAttribute('data-theme', theme);
        document.querySelectorAll('[data-theme-set]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
      });
    });

    // Connection status → status bar + top badge.
    var conn = document.getElementById('milpa-conn');
    var top = document.getElementById('milpa-topstate');
    window.MilpaShell.onStatus(function (state) {
      if (state === 'live') { conn.textContent = '◉ live'; conn.style.color = 'var(--accent-text)'; }
      else { conn.textContent = '○ offline'; conn.style.color = 'var(--text-muted)'; }
    });

    // The conversation stream: the backend's turn arrives as typed events, each rendered in its own voice.
    window.MilpaShell.on('agent.message', function (d) { appendMessage('agent', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('agent.thinking', function (d) { appendMessage('thinking', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('tool.call', function (d) { appendMessage('tool', { name: (d && d.name) || 'tool', result: (d && d.result) || '' }); });
    window.MilpaShell.on('task.added', function (d) { appendMessage('task', { title: (d && d.title) || '', status: (d && d.status) || 'todo' }); });
    window.MilpaShell.on('system.notice', function (d) { appendMessage('system', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('session.state', function (d) { setWorking(d && d.state === 'working'); });

    // Activity / audit stream: prepend each live fact as a mui-replay event.
    var list = document.getElementById('milpa-activity');
    window.MilpaShell.onAny(function (type, data) {
      var placeholder = list.querySelector('.mui-replay__actor');
      if (placeholder && placeholder.textContent.indexOf('no facts') === 0) { list.removeChild(placeholder.parentNode); }
      var li = document.createElement('li');
      li.className = 'mui-replay__event';
      li.innerHTML = '<span class="mui-replay__type"></span> <span class="mui-replay__actor"></span>';
      li.querySelector('.mui-replay__type').textContent = type;
      li.querySelector('.mui-replay__actor').textContent = JSON.stringify(data) + ' · live';
      list.insertBefore(li, list.firstChild);
    });

    // The consent gate.
    var gate = document.getElementById('milpa-gate');
    var badge = document.getElementById('milpa-decisions-badge');
    window.MilpaShell.on('gate.opened', function (g) {
      var args = (g && g.arguments) || {};
      var href = '/webauthn/intent?operation=' + encodeURIComponent(g.operation)
        + '&arguments=' + encodeURIComponent(JSON.stringify(args))
        + '&session=' + encodeURIComponent(g.session || '');
      gate.querySelector('[data-gate-op]').textContent = g.operation || '';
      gate.querySelector('[data-gate-args]').textContent = JSON.stringify(args);
      gate.querySelector('[data-gate-action]').textContent = 'An agent is asking to run ' + (g.operation || '') + '.';
      gate.querySelector('[data-gate-approve]').setAttribute('href', href);
      gate.hidden = false;
      badge.hidden = false;
      showTab('chat');
    });
    gate.querySelector('[data-gate-dismiss]').addEventListener('click', function () { gate.hidden = true; badge.hidden = true; });
  })();
</script>
<!-- The connection to the Mercure hub is rendered here when a hub is wired; it feeds MilpaShell. -->
<!--LIVE-->
</body>
</html>
HTML;
    }
}
