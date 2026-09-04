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
        private readonly ?\Milpa\DesktopApp\Live\ComposerField $composerField = null,
        private readonly ?\Milpa\DesktopApp\Live\Sidebar $sidebar = null,
        private readonly ?\Milpa\DesktopApp\Live\Topbar $topbar = null,
        private readonly ?\Milpa\DesktopApp\Live\Tabs $tabs = null,
        private readonly ?\Milpa\DesktopApp\Live\WorkBoard $workBoard = null,
        private readonly ?\Milpa\DesktopApp\Live\Activity $activity = null,
        private readonly ?\Milpa\DesktopApp\Live\Context $context = null,
        private readonly ?\Milpa\DesktopApp\Live\Gate $gate = null,
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

        // The agent session this Desktop drives (greenhouse decisions/0190): a stable id, kept in a cookie so
        // reloads continue the SAME governed session. Its `session.*` events ride the exact topic below.
        $agentSid = $request->getCookieParams()['milpa_agent_sid'] ?? null;
        $agentSid = \is_string($agentSid) && $agentSid !== '' ? $agentSid : 'desk-' . bin2hex(random_bytes(8));

        $cookies = [];
        if ($this->mercure !== null) {
            $cookies[] = 'milpa_agent_sid=' . $agentSid . '; Path=/; SameSite=Lax';
            // The hub reads the subscriber JWT from this cookie; the browser sends it with EventSource. It is
            // scoped to the shell topic AND this session's exact stream topic (greenhouse decisions/0190).
            $jwt = $this->mercure->subscriberJwt([\Milpa\DesktopApp\Live\MercureConfig::sessionTopic($agentSid)]);
            $cookies[] = 'mercureAuthorization=' . $jwt . '; Path=/; SameSite=Lax';
        }

        // The milpa/live boot payload the client runtime reads (#milpa-live-boot): the endpoint, the session
        // id, and the CSRF token — bound to this session and route. The remote field posts with these.
        $liveBoot = '';
        if ($this->composerField !== null) {
            $existing = $request->getCookieParams()[\Milpa\DesktopApp\Live\ComposerField::SESSION_COOKIE] ?? null;
            $liveSid = \is_string($existing) && $existing !== '' ? $existing : bin2hex(random_bytes(16));
            $cookies[] = \Milpa\DesktopApp\Live\ComposerField::SESSION_COOKIE . '=' . $liveSid . '; Path=/; SameSite=Lax; HttpOnly';
            $liveBoot = (string) json_encode([
                'endpoint' => \Milpa\DesktopApp\Live\ComposerField::ROUTE,
                'sessionId' => $liveSid,
                'csrfToken' => $this->composerField->csrfToken($liveSid),
            ], \JSON_UNESCAPED_SLASHES);
        }

        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'];
        if ($cookies !== []) {
            $headers['Set-Cookie'] = \count($cookies) === 1 ? $cookies[0] : $cookies;
        }

        return new Response(200, $headers, $this->html($composition, $liveBoot, $agentSid));
    }

    private function html(ShellComposition $composition, string $liveBoot = '', string $agentSid = ''): string
    {
        return str_replace(
            [
                '<!--RUNTIME-->', '<!--CONTEXT-->', '<!--CAPABILITIES-->', '<!--ENDPOINT-->',
                '<!--SIDEBAR-->', '<!--STATUS-->', '<!--WORK-->', '<!--ACTIVITY-->', '<!--COMPOSER-->', '<!--AUTHMODEL-->', '<!--LIVE-->', '<!--TOPBAR-->', '<!--TABS-->', '<!--GATE-->', '<!--LIVEBOOT-->', '<!--LIVESIGNALS-->', '<!--AGENTSID-->',
            ],
            [
                $this->runtimeScript(), $this->contextHtml($composition), $this->capabilitiesRows(), $this->endpointValue(),
                $this->sidebarHtml(), $this->statusCounters(), $this->workBoardHtml(), $this->activityHtml(), $this->composer(), $this->authModelLabel(), $this->connectScript($agentSid), $this->topbarHtml(), $this->tabsHtml(), $this->gateHtml(), str_replace('</', '<\/', $liveBoot), str_replace('</', '<\/', $this->liveSignals()), htmlspecialchars($agentSid, ENT_QUOTES),
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

        // The composer's text field IS a milpa/live component when the framework's UI system is wired
        // (greenhouse decisions/0189); otherwise a plain textarea (backwards-compatible fallback).
        $field = $this->composerField !== null
            ? $this->composerField->render()
            : '<textarea id="composer-input" class="mui-textarea" rows="2" placeholder="Write to the session…" style="border:0;outline:0;background:transparent;min-height:3rem;padding:0;font-size:var(--text-sm);font-family:var(--font-mono);width:100%;resize:none"></textarea>';
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
    {$field}
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-top:var(--space-2)">
      <button type="button" class="mui-btn mui-btn--ghost mui-btn--sm mui-btn--icon" aria-label="attach" style="border-radius:var(--radius-full)">＋</button>
      <span style="position:relative;display:inline-flex">
        <button type="button" class="mui-badge" id="milpa-mode-chip" aria-haspopup="true" aria-expanded="false" style="cursor:pointer;border:1px solid var(--border);font:inherit;display:inline-flex;align-items:center;gap:6px"><span id="milpa-mode-label" x-data x-text="\$store.milpa['composer.mode.label']">{$modeLabel}</span><span aria-hidden="true" style="opacity:.6">▾</span></button>
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

    /** The sidebar, rendered as a milpa/live component (greenhouse decisions/0189) — the shell's first
     *  pure-component surface. A fallback Sidebar is built from the same data when none was injected. */
    private function sidebarHtml(): string
    {
        return ($this->sidebar ?? new \Milpa\DesktopApp\Live\Sidebar('desktop-sidebar-fallback', $this->data, $this->events))->render();
    }

    /** The topbar, rendered as a milpa/live component (greenhouse decisions/0189) — the shell's second
     *  pure-component surface. A fallback Topbar is built from the same data when none was injected. */
    private function topbarHtml(): string
    {
        return ($this->topbar ?? new \Milpa\DesktopApp\Live\Topbar('desktop-topbar-fallback', $this->data, $this->events))->render();
    }

    /** The main tablist, rendered as a milpa/live component (greenhouse decisions/0189) — the shell's third
     *  pure-component surface. The panes and composer dock read the same `desktop.tab` signal to show/hide. */
    private function tabsHtml(): string
    {
        return ($this->tabs ?? new \Milpa\DesktopApp\Live\Tabs('desktop-tabs-fallback', $this->events))->render();
    }

    /** The initial shared signals, seeded into the page — one truth projected across the UI (decisions/0189). */
    private function liveSignals(): string
    {
        $modeLabels = ['ask' => 'Ask before changing', 'acknowledge' => 'Compatibility', 'auto' => 'Continue automatically'];
        $settings = $this->data?->settings() ?? [];
        $mode = \is_string($settings['mode'] ?? null) && isset($modeLabels[$settings['mode']]) ? $modeLabels[$settings['mode']] : 'Ask before changing';
        $counters = $this->data?->counters();

        return (string) json_encode([
            'composer.mode.label' => $mode,
            'session.state.label' => ucfirst(\is_array($counters) ? (string) $counters['state'] : 'idle'),
            'session.turns' => \is_array($counters) ? (int) $counters['turns'] : 0,
            'desktop.nav' => 'sessions',
            'desktop.tab' => 'chat',
            'desktop.gate.open' => false,
        ], \JSON_UNESCAPED_SLASHES);
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

    /** The Work board, rendered as a milpa/live component (greenhouse decisions/0189) — the shell's fourth
     *  pure-component surface. Moving a card still persists through /desktop/work (decisions/0484). */
    private function workBoardHtml(): string
    {
        return ($this->workBoard ?? new \Milpa\DesktopApp\Live\WorkBoard('desktop-work-board-fallback', $this->data, $this->events))->render();
    }

    /** The Activity tab, rendered as a milpa/live component (greenhouse decisions/0189) — the shell's fifth
     *  pure-component surface. Facts still arrive live over the hub, prepended to #milpa-activity. */
    private function activityHtml(): string
    {
        return ($this->activity ?? new \Milpa\DesktopApp\Live\Activity('desktop-activity-fallback', $this->data, $this->events))->render();
    }

    /** The Context tab, rendered as a milpa/live component (greenhouse decisions/0189) — the shell's sixth
     *  pure-component surface. Plugins still contribute panels through the composition (addPanel). */
    private function contextHtml(ShellComposition $composition): string
    {
        return ($this->context ?? new \Milpa\DesktopApp\Live\Context('desktop-context-fallback', $this->events))->render($composition->sections());
    }

    /** The consent gate, rendered as a milpa/live component (greenhouse decisions/0189) — the shell's seventh
     *  pure-component surface. Its visibility is the `desktop.gate.open` signal; live gate.opened fills it. */
    private function gateHtml(): string
    {
        return ($this->gate ?? new \Milpa\DesktopApp\Live\Gate('desktop-gate-fallback', $this->events))->render();
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
      // A governed turn's session.* projection (greenhouse decisions/0190), translated to the events the
      // shell already handles — so one set of listeners renders both the desktop feed and the agent stream.
      session: function (env) {
        var kind = env && env.kind;
        if (kind === 'activity') {
          var st = (env.activity && env.activity.state) || '';
          if (st === 'thinking') { this.emit('session.state', { state: 'working' }); }
          else if (st === 'ready') { this.emit('session.state', { state: 'idle' }); }
        } else if (kind === 'message') {
          this.emit('agent.message', { text: (env.message && env.message.content) || '' });
        } else if (kind === 'reasoning') {
          this.emit('agent.reasoning', { text: (env.reasoning && (env.reasoning.delta || env.reasoning.text)) || '' });
        } else if (kind === 'waiting') {
          this.emit('system.notice', { text: 'Waiting on you: ' + ((env.ended && env.ended.question) || '') });
        }
      },
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
    private function connectScript(string $agentSid = ''): string
    {
        if ($this->mercure === null) {
            return "<script>window.MilpaShell.status('offline');</script>";
        }

        // Subscribe to TWO exact topics on one connection: the shell topic (desktop ShellEvents) and this
        // session's stream (a governed turn's session.* events, greenhouse decisions/0190). Exact topics —
        // not a URI template — so the hub authorizes and delivers them without matching ambiguity.
        $url = json_encode(
            $this->mercure->publicUrl . '?topic=' . rawurlencode($this->mercure->topic)
            . '&topic=' . rawurlencode(\Milpa\DesktopApp\Live\MercureConfig::sessionTopic($agentSid)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return <<<HTML
<script>
  (function () {
    var es = new EventSource({$url}, { withCredentials: true });
    es.onopen = function () { window.MilpaShell.status('live'); };
    es.onerror = function () { window.MilpaShell.status('offline'); };
    es.onmessage = function (e) {
      var env; try { env = JSON.parse(e.data); } catch (err) { return; }
      // Two shapes on one connection: a desktop ShellEvent carries `event`; a governed turn's session
      // projection carries `kind` (activity/message/waiting). Map the session ones to the shell's UI.
      if (env && typeof env.event === 'string') { window.MilpaShell.emit(env.event, env.data); return; }
      if (env && typeof env.kind === 'string') { window.MilpaShell.session(env); }
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
  /* Live thinking block: the words assemble in front of the user, then collapse to a toggle. A quiet,
     bordered aside — visibly the model's private reasoning, never mistaken for its answer. */
  .milpa-think { font-style: normal; border-inline-start: 2px solid var(--border); padding-inline-start: var(--space-3); }
  .milpa-think__toggle { display: inline-flex; align-items: center; gap: var(--space-2); padding: 2px 0; background: none; border: none; cursor: pointer; font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); letter-spacing: .04em; }
  .milpa-think__toggle:hover { color: var(--text-secondary); }
  .milpa-think[data-open="1"] .milpa-think__toggle::after { content: ' ▾'; }
  .milpa-think[data-open="0"] .milpa-think__toggle::after { content: ' ▸'; }
  .milpa-think__body { margin-top: var(--space-2); max-height: 16rem; overflow-y: auto; font-family: var(--font-mono); font-size: var(--text-2xs); line-height: var(--leading-relaxed); color: var(--text-muted); white-space: pre-wrap; }
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
    <!--SIDEBAR-->

    <!--TOPBAR-->

    <main class="mui-shell__main mui-shell__main--wide" style="grid-row:2;grid-column:2;min-height:0;padding:0;display:flex;flex-direction:column;overflow:hidden">
      <div class="view" data-view="session" x-data style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden">
      <!--TABS-->

      <div style="flex:1;min-height:0;overflow:auto;padding:var(--space-6) var(--space-8)">

        <section class="tabpane" data-pane="chat" id="milpa-chat" :hidden="$store.milpa['desktop.tab'] !== 'chat'">
          <div class="msg msg--system">session opened · nothing runs on open</div>
          <div class="msg msg--user"><div><span class="msg__meta">you · now</span><p style="margin:var(--space-2) 0 0;font-size:var(--text-sm)">Enable the devtools capability on this app.</p></div></div>
          <div class="msg msg--thinking"><span class="msg__meta">agent · thinking</span><p>Reading the app's capabilities and the parked gate before acting…</p></div>
          <div class="msg msg--tool"><div><span class="msg__tool-name">capabilities.list</span><span>→ 6 capabilities</span></div></div>
          <div class="msg msg--agent"><span class="msg__meta">agent · local</span><p>When an agent needs your decision, it parks a gate here — a durable question, not a modal. Approve it with your passkey, in this origin.</p></div>
          <div class="msg msg--task"><div><span class="msg__mark">+</span><span class="msg__title">Enable devtools capability</span><span class="mui-badge" style="margin-inline-start:auto">todo</span></div></div>

          <!-- The consent gate is the `desktop-gate` component (greenhouse decisions/0189): hidden until an
               agent parks a question; its visibility is the shared `desktop.gate.open` signal. -->
          <!--GATE-->
        </section>

        <section class="tabpane" data-pane="work" hidden :hidden="$store.milpa['desktop.tab'] !== 'work'">
          <p style="color:var(--text-secondary);font-size:var(--text-sm);margin:0 0 var(--space-4)">The session's work board — todo items by status.</p>
          <!--WORK-->
        </section>

        <section class="tabpane" data-pane="activity" hidden :hidden="$store.milpa['desktop.tab'] !== 'activity'" style="display:grid;grid-template-columns:1fr 20rem;gap:var(--space-6);align-items:start">
          <!--ACTIVITY-->
        </section>

        <section class="tabpane" data-pane="context" hidden :hidden="$store.milpa['desktop.tab'] !== 'context'">
          <!-- The panel grid is the `desktop-context` component; plugins contribute panels via addPanel. -->
          <!--CONTEXT-->
        </section>

      </div>
      <!-- The composer is docked below the scroll, sticky at the bottom: messages flow above it and it
           stays put. Shown only on the Conversation tab. -->
      <div id="milpa-composer-dock" :hidden="$store.milpa['desktop.tab'] !== 'chat'" style="flex:none;padding:var(--space-3) var(--space-8) var(--space-5);border-top:1px solid var(--border-subtle);background:var(--bg)"><!--COMPOSER--></div>
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
    // The composer's textarea — the milpa/live component's field when wired, else the fallback (same query).
    var composerInput = document.querySelector('#milpa-composer-dock textarea');
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

    // The live thinking block (greenhouse decisions/0190): reasoning deltas stream in — the words assemble
    // in front of the user — and when the turn produces its message (or ends) the block COLLAPSES to a
    // toggle ("thought for Ns"), which re-opens on click. A distinct visual voice from the agent's message.
    var reasoningEl = null, reasoningBody = null, reasoningStart = 0;
    function appendReasoning(delta) {
      if (!chat || !delta) { return; }
      if (!reasoningEl) {
        reasoningStart = Date.now();
        // Local refs so each block's toggle keeps working after endReasoning() clears the shared ones.
        var blockEl = document.createElement('div');
        blockEl.className = 'msg msg--thinking milpa-think';
        blockEl.setAttribute('data-open', '1');
        var head = document.createElement('button');
        head.type = 'button';
        head.className = 'milpa-think__toggle';
        head.textContent = '◈ thinking…';
        var bodyEl = document.createElement('div');
        bodyEl.className = 'milpa-think__body';
        head.addEventListener('click', function () {
          var open = blockEl.getAttribute('data-open') === '1';
          blockEl.setAttribute('data-open', open ? '0' : '1');
          bodyEl.hidden = open;
        });
        blockEl.appendChild(head);
        blockEl.appendChild(bodyEl);
        chat.appendChild(blockEl);
        reasoningEl = blockEl;
        reasoningBody = bodyEl;
      }
      reasoningBody.textContent += delta;
      reasoningEl.scrollIntoView({ block: 'end' });
    }
    function endReasoning() {
      if (!reasoningEl) { return; }
      var secs = Math.max(1, Math.round((Date.now() - reasoningStart) / 1000));
      var head = reasoningEl.querySelector('.milpa-think__toggle');
      if (head) { head.textContent = '◈ thought for ' + secs + 's'; }
      reasoningEl.setAttribute('data-open', '0');
      if (reasoningBody) { reasoningBody.hidden = true; }
      reasoningEl = null;
      reasoningBody = null;
    }

    // The agent session this Desktop drives (greenhouse decisions/0190): the server minted it, set it in a
    // cookie, and scoped the hub JWT + the EventSource to its exact stream topic — so a governed turn's
    // session.* events reach this shell live.
    var agentSession = '<!--AGENTSID-->';

    function send() {
      if (!composerInput) { return; }
      var text = composerInput.value.trim();
      if (text === '') { return; }
      appendMessage('user', { text: text });
      composerInput.value = '';
      // Notify the milpa/live component (Alpine x-model / @input) so its state clears too.
      composerInput.dispatchEvent(new Event('input', { bubbles: true }));
      refreshSend();
      composerInput.focus();
      // Start a governed turn over the HTTP surface (greenhouse decisions/0190). The working/idle badge
      // and (once projected) the reasoning stream arrive live over the hub on this session's topic; the
      // final answer comes back here. `mode: ask` keeps mutating tools behind their gate.
      fetch('/agent', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: text, session: agentSession, mode: 'ask' })
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.ok && res.answer) { appendMessage('agent', { text: res.answer }); }
        else if (res && res.paused) { appendMessage('system', { text: res.hint || 'The agent is waiting on your decision.' }); }
        else if (res && res.error) { appendMessage('system', { text: res.error }); }
      }).catch(function () { appendMessage('system', { text: 'The turn could not be reached.' }); });
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
      // Set the shared session-state signal (the badge's text reads it); keep the accent class local.
      if (window.MilpaLive && window.MilpaLive.signal) { window.MilpaLive.signal('session.state.label', working ? 'Working' : 'Idle'); }
      var top = document.getElementById('milpa-topstate');
      if (top) { top.className = working ? 'mui-badge mui-badge--accent mui-badge--dot' : 'mui-badge'; }
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

    // Tabs are the `desktop-tabs` Milpa Component (greenhouse decisions/0189): the tablist sets the shared
    // `desktop.tab` signal on click, and the panes + composer dock read it to show/hide (Alpine `:hidden`).
    // No imperative click-wiring here; switching a tab is setting one signal.
    function showTab(name) {
      if (window.MilpaLive && MilpaLive.signal) { MilpaLive.signal('desktop.tab', name); }
    }

    // Sidebar navigation: swap the whole main between the session view and settings — same shell, not a window.
    var navItems = document.querySelectorAll('.mui-sidebar__item[data-nav]');
    var navToView = { sessions: 'session', decisions: 'decisions', capabilities: 'capabilities', settings: 'settings' };
    function showView(view) {
      // Swap the whole main between its views — same shell, not a window. The auth overlay is a `.view`
      // too but is opened on demand, so it is never toggled by navigation. The sidebar's active-nav highlight
      // is the `desktop.nav` signal (Alpine binds aria-current to it), so this only switches the view.
      document.querySelectorAll('.view').forEach(function (v) {
        if (v.getAttribute('data-view') !== 'auth') { v.hidden = v.getAttribute('data-view') !== view; }
      });
    }
    navItems.forEach(function (n) {
      n.addEventListener('click', function (e) {
        e.preventDefault();
        showView(navToView[n.getAttribute('data-nav')] || 'session');
      });
    });

    // The composer field's server round-trip on blur (validate + cross-component effects) is now the
    // framework's own remote runtime (milpaFieldRemote, bound via `remote`); no Desktop JS drives it.

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
          // Set the SHARED signal — the chip and the topbar badge both read it, so both update (0189).
          if (window.MilpaLive && window.MilpaLive.signal) { window.MilpaLive.signal('composer.mode.label', opt.getAttribute('data-label')); }
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
    // Reasoning deltas stream into the live thinking block; the agent's message (or the turn ending) closes it.
    window.MilpaShell.on('agent.reasoning', function (d) { appendReasoning((d && d.text) || ''); });
    window.MilpaShell.on('agent.message', function (d) { endReasoning(); appendMessage('agent', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('agent.thinking', function (d) { appendMessage('thinking', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('tool.call', function (d) { appendMessage('tool', { name: (d && d.name) || 'tool', result: (d && d.result) || '' }); });
    window.MilpaShell.on('task.added', function (d) { appendMessage('task', { title: (d && d.title) || '', status: (d && d.status) || 'todo' }); });
    window.MilpaShell.on('system.notice', function (d) { appendMessage('system', { text: (d && d.text) || '' }); });
    window.MilpaShell.on('session.state', function (d) { if (d && d.state !== 'working') { endReasoning(); } setWorking(d && d.state === 'working'); });

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

    // The consent gate is the `desktop-gate` component (greenhouse decisions/0189): its VISIBILITY is the
    // shared `desktop.gate.open` signal (the card binds :hidden to it; Dismiss sets it via @click). The live
    // gate.opened event fills the dynamic fields and opens the signal — the content transport is unchanged.
    var gate = document.getElementById('milpa-gate');
    var badge = document.getElementById('milpa-decisions-badge');
    function setGateOpen(v) {
      if (window.MilpaLive && MilpaLive.signal) { MilpaLive.signal('desktop.gate.open', v); } else if (gate) { gate.hidden = !v; }
    }
    window.MilpaShell.on('gate.opened', function (g) {
      var args = (g && g.arguments) || {};
      var href = '/webauthn/intent?operation=' + encodeURIComponent(g.operation)
        + '&arguments=' + encodeURIComponent(JSON.stringify(args))
        + '&session=' + encodeURIComponent(g.session || '');
      gate.querySelector('[data-gate-op]').textContent = g.operation || '';
      gate.querySelector('[data-gate-args]').textContent = JSON.stringify(args);
      gate.querySelector('[data-gate-action]').textContent = 'An agent is asking to run ' + (g.operation || '') + '.';
      gate.querySelector('[data-gate-approve]').setAttribute('href', href);
      setGateOpen(true);
      badge.hidden = false;
      showTab('chat');
    });
    // Dismiss closes the signal (via @click in the markup); mirror the decisions badge here.
    gate.querySelector('[data-gate-dismiss]').addEventListener('click', function () { badge.hidden = true; });
  })();
</script>
<!-- The connection to the Mercure hub is rendered here when a hub is wired; it feeds MilpaShell. -->
<!--LIVE-->
<!-- milpa/live — the framework's official UI system. The boot payload feeds the remote runtime; the local
     and remote runtimes register their Alpine factories BEFORE Alpine boots. Served from the package. -->
<script id="milpa-live-boot" type="application/json"><!--LIVEBOOT--></script>
<script id="milpa-live-signals" type="application/json"><!--LIVESIGNALS--></script>
<!-- The mode is remembered across reloads; the session summary is DERIVED from the state and turn signals. -->
<script id="milpa-live-persist" type="application/json">["composer.mode.label"]</script>
<script id="milpa-live-computed" type="application/json">{"session.summary":{"template":"{session.state.label} · {session.turns} turns"}}</script>
<script src="/desktop/assets/milpa-live.js"></script>
<script src="/desktop/assets/milpa-live-remote.js"></script>
<script src="/desktop/assets/alpine.min.js"></script>
</body>
</html>
HTML;
    }
}
