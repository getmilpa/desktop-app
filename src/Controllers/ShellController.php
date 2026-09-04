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
        private readonly ?\Milpa\DesktopApp\Live\Thinking $thinking = null,
        private readonly ?\Milpa\DesktopApp\Live\AgentMessage $agentMessage = null,
        private readonly ?\Milpa\DesktopApp\Live\MessagePrototypes $messages = null,
        private readonly ?\Milpa\DesktopApp\Live\Conversation $conversation = null,
    ) {
    }

    /** The plainer message prototypes (user/tool/task/system), or a fallback set (greenhouse decisions/0191). */
    private function messages(): \Milpa\DesktopApp\Live\MessagePrototypes
    {
        return $this->messages ?? new \Milpa\DesktopApp\Live\MessagePrototypes('desktop-messages-fallback', $this->events);
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
                '<!--SIDEBAR-->', '<!--STATUS-->', '<!--WORK-->', '<!--ACTIVITY-->', '<!--COMPOSER-->', '<!--AUTHMODEL-->', '<!--LIVE-->', '<!--TOPBAR-->', '<!--TABS-->', '<!--GATE-->', '<!--CONVERSATION-->', '<!--THINKING-->', '<!--AGENTMSG-->', '<!--USERMSG-->', '<!--TOOLMSG-->', '<!--TASKMSG-->', '<!--SYSMSG-->', '<!--RESULTMSG-->', '<!--LIVEBOOT-->', '<!--LIVESIGNALS-->', '<!--AGENTSID-->',
            ],
            [
                $this->runtimeScript(), $this->contextHtml($composition), $this->capabilitiesRows(), $this->endpointValue(),
                $this->sidebarHtml(), $this->statusCounters(), $this->workBoardHtml(), $this->activityHtml(), $this->composer(), $this->authModelLabel(), $this->connectScript($agentSid), $this->topbarHtml(), $this->tabsHtml(), $this->gateHtml(), $this->conversationHtml(), $this->thinkingHtml(), $this->agentMessageHtml(), $this->messages()->user(), $this->messages()->tool(), $this->messages()->task(), $this->messages()->system(), $this->messages()->resultClaim(), str_replace('</', '<\/', $liveBoot), str_replace('</', '<\/', $this->liveSignals()), htmlspecialchars($agentSid, ENT_QUOTES),
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
        <span id="milpa-charcount" aria-live="polite" style="color:var(--text-muted);min-width:0"></span>
        <button type="button" class="composer-chip" data-open-panel="session" style="border:1px solid var(--border);border-radius:var(--radius-full);background:var(--surface);color:var(--text);padding:4px 10px;cursor:pointer;font:inherit">◈ <span x-data x-text="\$store.milpa['session.counters']">{$c['turns']} turns · {$c['tool_calls']} tools</span></button>
        <button type="button" class="composer-chip" data-open-panel="context" style="border:1px solid var(--border);border-radius:var(--radius-full);background:var(--surface);color:var(--text);padding:4px 10px;cursor:pointer;font:inherit">▤ <span x-data x-text="\$store.milpa['context.usage']">{$tokens}/{$window}</span></button>
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
        $ctx = $this->data?->context() ?? ['tokens' => 0, 'window' => 32768];

        // Every counter the UI shows is a SIGNAL — one truth, projected to the composer chips, the status bar
        // and the panels alike (greenhouse decisions/0191, Rod). The live feed and the turn update these; every
        // place that reads them updates at once. A value shown that is not a signal is a value that goes stale.
        return (string) json_encode([
            'composer.mode.label' => $mode,
            'session.state.label' => ucfirst(\is_array($counters) ? (string) $counters['state'] : 'idle'),
            'session.turns' => \is_array($counters) ? (int) $counters['turns'] : 0,
            'session.steps' => \is_array($counters) ? (int) $counters['steps'] : 0,
            'session.tokens' => \is_array($counters) ? (int) $counters['tokens'] : 0,
            'session.tool_calls' => \is_array($counters) ? (int) $counters['tool_calls'] : 0,
            'context.used' => $this->kfmt((int) $ctx['tokens']),
            'context.window' => $this->kfmt((int) $ctx['window']),
            'desktop.nav' => 'sessions',
            'desktop.tab' => 'chat',
            'desktop.gate.open' => false,
        ], \JSON_UNESCAPED_SLASHES);
    }

    /** The status bar's counters — bound to the shared `session.status` signal so they update live with the
     *  composer chips and the panels (one truth, greenhouse decisions/0191). Seeded from the session (0482). */
    private function statusCounters(): string
    {
        $c = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];

        $seed = sprintf('%d turns · %d steps · %d tokens · %d tool calls', $c['turns'], $c['steps'], $c['tokens'], $c['tool_calls']);

        return '<span x-data x-text="$store.milpa[\'session.status\']">' . $seed . '</span>';
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

    /** The conversation's inner content (greenhouse decisions/0191): the empty state + envelope. The chat is a
     *  component that composes the message components; this fills its container. */
    private function conversationHtml(): string
    {
        return ($this->conversation ?? new \Milpa\DesktopApp\Live\Conversation('desktop-conversation-fallback', $this->events))->render();
    }

    /** The thinking component's prototype (greenhouse decisions/0191): the conversation clones it per turn and
     *  feeds it the reasoning by events. The first message type made a real Milpa Component. */
    private function thinkingHtml(): string
    {
        return ($this->thinking ?? new \Milpa\DesktopApp\Live\Thinking('desktop-thinking-fallback', $this->events))->render();
    }

    /** The agent-message component's prototype (greenhouse decisions/0191): the conversation clones it per
     *  answer, fills the body, and its foot tools (copy, regenerate) act through a delegated handler. */
    private function agentMessageHtml(): string
    {
        return ($this->agentMessage ?? new \Milpa\DesktopApp\Live\AgentMessage('desktop-agent-message-fallback', $this->events))->render();
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
          else if (st === 'tool') {
            // A tool ran: show it in the conversation and count it into the shared tool_calls signal.
            this.emit('tool.call', { name: (env.activity && env.activity.detail) || 'tool', result: (env.activity && env.activity.result) || '' });
            if (window.MilpaLive && MilpaLive.signal) { MilpaLive.signal('session.tool_calls', (parseInt(MilpaLive.signal('session.tool_calls'), 10) || 0) + 1); }
          }
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
  /* One border, one authority: the milpa/live field renders its own border by default, but here it lives
     INSIDE the composer box — a second border reads as a second authority (Rod's doctrine). The box owns
     the frame and the focus ring; the field is seamless — no border, no background, no ring of its own. */
  .milpa-composer-box .mui-field { margin: 0; }
  .milpa-composer-box .mui-textarea,
  .milpa-composer-box .mui-textarea:focus,
  .milpa-composer-box .mui-textarea:focus-visible {
    border: 0; outline: 0; background: transparent; box-shadow: none; padding: 0;
  }
  /* No visible scrollbars anywhere — scrolling still works. */
  * { scrollbar-width: none; -ms-overflow-style: none; }
  *::-webkit-scrollbar { width: 0; height: 0; display: none; }
  /* The message stream: one visual language, a distinct voice per kind. New messages arrive at the bottom
     and the composer is docked below (sticky), so the thread reads top→down and the box never moves. */
  #milpa-chat { display: flex; flex-direction: column; gap: var(--space-5); max-width: 88ch; }
  /* The conversation's empty state hides itself the moment a message component is cloned in. */
  #milpa-chat:has(.msg) .milpa-empty-convo { display: none; }
  .msg__meta { font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); display: block; }
  .msg--user { display: flex; justify-content: flex-end; }
  .msg--user > div { max-width: 56ch; padding: var(--space-3) var(--space-5); border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface); }
  /* The user is a human PEER — a contrasting bubble (--surface) on the right. The agent is the SYSTEM
     speaking, not another human (Rod): its own bubble on the left, but tinted toward the app surface —
     quieter, closer to the system chrome — and a subtle border, so it reads as the house's voice, not a peer's. */
  .msg--agent { align-self: flex-start; max-width: 72ch; padding: var(--space-3) var(--space-5); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); background: var(--surface); background: color-mix(in oklab, var(--surface) 55%, var(--bg)); }
  .msg--agent > p { margin: var(--space-2) 0 0; font-size: var(--text-sm); line-height: var(--leading-relaxed); text-wrap: pretty; }
  /* The agent's answer is rendered markdown (safe subset): headings, lists, code, emphasis — legible, not raw. */
  .msg__md { margin-top: var(--space-2); font-size: var(--text-sm); line-height: var(--leading-relaxed); text-wrap: pretty; }
  .msg__md p { margin: 0 0 var(--space-2); }
  .msg__md p:last-child { margin-bottom: 0; }
  .msg__md .md-h { margin: var(--space-3) 0 var(--space-2); font-size: var(--text-base); font-weight: var(--weight-medium); }
  .msg__md .md-ul { margin: 0 0 var(--space-2); padding-inline-start: var(--space-5); display: flex; flex-direction: column; gap: 2px; }
  .msg__md .md-code { font-family: var(--font-mono); font-size: var(--text-xs); padding: 1px 5px; border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--border-subtle); }
  .msg__md .md-pre { margin: var(--space-2) 0; padding: var(--space-3); border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--border-subtle); overflow-x: auto; }
  .msg__md .md-pre code { font-family: var(--font-mono); font-size: var(--text-2xs); line-height: var(--leading-relaxed); }
  .msg__md a { color: var(--accent-text); text-decoration: underline; }
  /* Agent message tools: a quiet row of icon buttons at the foot of the answer — copy, regenerate. They stay
     dim until the message is hovered, then come forward; a copied tool flashes the accent. */
  .msg__tools { display: flex; gap: var(--space-1); margin-top: var(--space-2); opacity: 0; transition: opacity var(--dur-fast, 120ms) ease-out; }
  .msg--agent:hover .msg__tools, .msg__tools:focus-within { opacity: 1; }
  .msg__tool-btn { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; padding: 0; border: none; border-radius: var(--radius-sm); background: none; color: var(--text-muted); cursor: pointer; transition: color var(--dur-fast, 120ms) ease-out, background var(--dur-fast, 120ms) ease-out; }
  .msg__tool-btn:hover { color: var(--text); background: var(--surface); }
  .msg__tool-btn.is-done { color: var(--accent-text); }
  /* The ledger's verdict, riding the answer's tool row (Rod's ask — saves a line): a compact mark + label at
     the far end, with a tooltip on hover/focus that says WHAT the ledger judged. Anchored right so it never
     runs off-screen. */
  .msg__verdict { position: relative; display: inline-flex; align-items: center; gap: var(--space-1); margin-inline-start: auto; padding: 0 var(--space-2); height: 28px; border-radius: var(--radius-sm); font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); cursor: help; }
  .msg__verdict[data-verified="1"] .msg__verdict-mark { color: var(--success); }
  .msg__verdict[data-verified="0"] .msg__verdict-mark { color: var(--warning); }
  .msg__verdict[data-verified="0"] { color: var(--warning); }
  .msg__verdict:hover, .msg__verdict:focus-visible { color: var(--text-secondary); background: var(--surface); }
  .msg__verdict:focus-visible { outline: 2px solid var(--accent-subtle); outline-offset: 2px; }
  .msg__verdict-tip { position: absolute; bottom: calc(100% + 8px); left: 0; z-index: 70; width: max-content; max-width: min(22rem, 60vw); padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); background: var(--surface-raised); border: 1px solid var(--border); box-shadow: var(--shadow-lg); color: var(--text-secondary); font-size: var(--text-2xs); line-height: var(--leading-relaxed); letter-spacing: normal; white-space: normal; text-align: left; opacity: 0; transform: translateY(4px); pointer-events: none; transition: opacity .18s ease, transform .18s ease; }
  .msg__verdict:hover .msg__verdict-tip, .msg__verdict:focus-visible .msg__verdict-tip, .msg__verdict:focus-within .msg__verdict-tip { opacity: 1; transform: translateY(0); }
  @media (prefers-reduced-motion: reduce) { .msg__verdict-tip { transition: none; } }
  /* Thinking: the agent reasoning aloud — dimmed and italic, clearly not final speech. */
  .msg--thinking { color: var(--text-muted); font-style: italic; }
  .msg--thinking > p { margin: var(--space-1) 0 0; font-size: var(--text-xs); line-height: var(--leading-relaxed); white-space: pre-wrap; }
  /* Live thinking block: the words assemble in front of the user WHILE the model is still reasoning — a
     breathing spark, typing dots, and an accent edge say "alive"; all of it stops the instant it's done and
     the block settles to a quiet, collapsible aside — the model's private reasoning, never its answer. */
  .milpa-think { font-style: normal; border-inline-start: 2px solid var(--border); padding-inline-start: var(--space-3); transition: border-color .45s ease; }
  .milpa-think[data-thinking-active="1"] { border-inline-start-color: var(--accent); }
  .milpa-think__toggle { display: inline-flex; align-items: center; gap: var(--space-2); padding: 2px 0; background: none; border: none; cursor: pointer; font-family: var(--font-mono); font-size: var(--text-2xs); color: var(--text-muted); letter-spacing: .04em; transition: color .3s ease; }
  .milpa-think__toggle:hover { color: var(--text-secondary); }
  .milpa-think[data-thinking-active="1"] .milpa-think__toggle { color: var(--text-secondary); }
  /* The spark: a quiet diamond at rest, a breathing accent mark while the model reasons. */
  .milpa-think__spark { display: inline-block; color: var(--text-muted); }
  .milpa-think[data-thinking-active="1"] .milpa-think__spark { color: var(--accent-text); animation: milpa-think-pulse 1.6s ease-in-out infinite; }
  /* Typing dots: the universal "working" cue — only while active, hidden once the block settles. */
  .milpa-think__dots { display: none; align-items: center; gap: 3px; }
  .milpa-think[data-thinking-active="1"] .milpa-think__dots { display: inline-flex; }
  .milpa-think__dots i { width: 3px; height: 3px; border-radius: 50%; background: var(--accent-text); opacity: .25; animation: milpa-think-dot 1.2s ease-in-out infinite; }
  .milpa-think__dots i:nth-child(2) { animation-delay: .18s; }
  .milpa-think__dots i:nth-child(3) { animation-delay: .36s; }
  @keyframes milpa-think-pulse { 0%, 100% { opacity: .5; transform: scale(.88); } 50% { opacity: 1; transform: scale(1.18); } }
  @keyframes milpa-think-dot { 0%, 100% { opacity: .25; transform: translateY(0); } 50% { opacity: 1; transform: translateY(-1.5px); } }
  /* The caret shows only once the block is done (active=0): no collapse chevron competes with the live dots. */
  .milpa-think[data-thinking-active="0"][data-open="1"] .milpa-think__toggle::after { content: ' ▾'; opacity: .6; }
  .milpa-think[data-thinking-active="0"][data-open="0"] .milpa-think__toggle::after { content: ' ▸'; opacity: .6; }
  /* Collapse animates (max-height), never a hard cut. */
  .milpa-think__body { margin-top: var(--space-2); max-height: 16rem; overflow-y: auto; font-family: var(--font-mono); font-size: var(--text-2xs); line-height: var(--leading-relaxed); color: var(--text-muted); white-space: pre-wrap; transition: max-height .3s ease, opacity .22s ease, margin-top .3s ease; }
  .milpa-think[data-open="0"] .milpa-think__body { max-height: 0; margin-top: 0; opacity: 0; overflow: hidden; }
  @media (prefers-reduced-motion: reduce) {
    .milpa-think[data-thinking-active="1"] .milpa-think__spark { animation: none; }
    .milpa-think__dots i { animation: none; opacity: .8; }
    .milpa-think, .milpa-think__toggle, .milpa-think__body { transition: none; }
  }
  /* Tool call: a compact mono card, the machinery made legible — name + summary, the raw result collapsed. */
  .msg--tool .msg__tool-head { display: inline-flex; align-items: baseline; gap: var(--space-2); padding: var(--space-2) var(--space-3); border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--border-subtle); font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text); cursor: pointer; }
  .msg--tool .msg__tool-head:hover { border-color: var(--border); }
  .msg--tool .msg__tool-name { color: var(--accent-text); }
  .msg--tool .msg__tool-summary { color: var(--text-secondary); }
  .msg--tool[data-open="1"] .msg__tool-head::after { content: ' ▾'; opacity: .5; }
  .msg--tool[data-open="0"] .msg__tool-head::after { content: ' ▸'; opacity: .5; }
  .msg--tool[data-open="0"] .msg__tool-raw { display: none; }
  .msg--tool .msg__tool-raw { margin: var(--space-2) 0 0; max-height: 18rem; overflow: auto; padding: var(--space-3); border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--border-subtle); font-family: var(--font-mono); font-size: var(--text-2xs); line-height: var(--leading-relaxed); color: var(--text-muted); white-space: pre-wrap; }
  /* Result claim: the ledger's verdict on the turn — a quiet line, green when verified, warning when disputed.
     An ⓘ affordance + a hover/focus tooltip explain WHAT the ledger judged, so "verified" is never opaque. */
  .msg--result { position: relative; display: inline-flex; align-items: baseline; gap: var(--space-2); font-family: var(--font-mono); font-size: var(--text-2xs); cursor: help; }
  .msg--result[data-verified="1"] .msg__result-mark { color: var(--success); }
  .msg--result[data-verified="0"] .msg__result-mark { color: var(--warning); }
  .msg--result[data-verified="0"] { color: var(--warning); }
  .msg__result-info { color: var(--text-muted); opacity: .5; font-size: .95em; transition: opacity .2s ease, color .2s ease; }
  .msg--result:hover .msg__result-info, .msg--result:focus-visible .msg__result-info { opacity: 1; color: var(--accent-text); }
  .msg--result:focus-visible { outline: 2px solid var(--accent-subtle); outline-offset: 3px; border-radius: var(--radius-sm); }
  .msg__result-tip { position: absolute; bottom: calc(100% + 8px); left: 0; z-index: 70; width: max-content; max-width: min(22rem, 60vw); padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); background: var(--surface-raised); border: 1px solid var(--border); box-shadow: var(--shadow-lg); color: var(--text-secondary); font-size: var(--text-2xs); line-height: var(--leading-relaxed); letter-spacing: normal; text-transform: none; white-space: normal; opacity: 0; transform: translateY(4px); pointer-events: none; transition: opacity .18s ease, transform .18s ease; }
  .msg--result:hover .msg__result-tip, .msg--result:focus-visible .msg__result-tip, .msg--result:focus-within .msg__result-tip { opacity: 1; transform: translateY(0); }
  @media (prefers-reduced-motion: reduce) { .msg__result-tip { transition: none; } }
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

        <!-- The conversation is a Milpa Component that COMPOSES the message components (greenhouse
             decisions/0191): user/agent/thinking/tool/task/system messages are cloned in from their own
             components' prototypes, and the consent gate lives here too. Its empty state hides once a
             message lands. -->
        <section class="tabpane milpa-chat" data-pane="chat" id="milpa-chat" data-milpa-component="desktop-conversation" data-milpa-component-id="conversation" :hidden="$store.milpa['desktop.tab'] !== 'chat'">
          <!--CONVERSATION-->

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

      <!-- The thinking message component's prototype (greenhouse decisions/0191): the conversation clones this
           per turn — Alpine hydrates each clone — and feeds it the reasoning by `thinking:delta`/`thinking:done`
           events. A plugin extends every thinking block by hooking the component's render events, once, here. -->
      <template id="milpa-thinking-proto"><!--THINKING--></template>

      <!-- The agent-message component's prototype (greenhouse decisions/0191): cloned per answer, filled into
           its body, its foot tools (copy, regenerate) acting through the conversation's delegated handler. -->
      <template id="milpa-agent-msg-proto"><!--AGENTMSG--></template>

      <!-- The plainer message components' prototypes (greenhouse decisions/0191): the conversation clones the
           one for each message kind and fills its data regions. Each a declared, event-emitting component. -->
      <template id="milpa-user-msg-proto"><!--USERMSG--></template>
      <template id="milpa-tool-msg-proto"><!--TOOLMSG--></template>
      <template id="milpa-task-msg-proto"><!--TASKMSG--></template>
      <template id="milpa-system-msg-proto"><!--SYSMSG--></template>
      <template id="milpa-result-msg-proto"><!--RESULTMSG--></template>

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
    // The draft's token count lives in the composer footer now (Rod's minimalist UX): live, quiet, empty at
    // zero. A client-side estimate (~4 chars/token — there is no tokenizer in the browser); the real usage is
    // the context figure. The unit is "tokens", not "chars", because that is what the budget is spent in.
    var charCount = document.getElementById('milpa-charcount');
    function refreshCount() {
      if (!charCount) { return; }
      var n = composerInput ? composerInput.value.length : 0;
      var toks = Math.ceil(n / 4);
      charCount.textContent = n > 0 ? ('~' + toks + ' tokens') : '';
    }
    if (composerInput) {
      composerInput.addEventListener('input', function () {
        composerPanels.forEach(function (p) { p.hidden = true; });
        refreshSend();
        refreshCount();
      });
    }
    // A SAFE markdown renderer for the agent's message (greenhouse decisions/0191, Rod: the answer shows
    // markdown intent). It ESCAPES the model's text first — the model's output is never injected as raw HTML —
    // then applies a small, known-safe subset (fenced/inline code, bold, italic, links with a safe scheme,
    // headings, lists). Every tag it emits is one it wrote; the only text that survives is escaped.
    function renderMarkdown(src) {
      src = String(src == null ? '' : src);
      function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
      var blocks = [];
      src = src.replace(/```[^\n]*\n?([\s\S]*?)```/g, function (_, code) { blocks.push('<pre class="md-pre"><code>' + esc(code.replace(/\n$/, '')) + '</code></pre>'); return '�B' + (blocks.length - 1) + '�'; });
      var out = esc(src);
      out = out.replace(/`([^`\n]+)`/g, '<code class="md-code">$1</code>');
      out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      out = out.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
      out = out.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
      var lines = out.split('\n'), html = '', inList = false;
      for (var i = 0; i < lines.length; i++) {
        var ln = lines[i], h = ln.match(/^(#{1,3})\s+(.*)$/), li = ln.match(/^\s*[-*]\s+(.*)$/);
        if (li) { if (!inList) { html += '<ul class="md-ul">'; inList = true; } html += '<li>' + li[1] + '</li>'; continue; }
        if (inList) { html += '</ul>'; inList = false; }
        if (h) { var lvl = h[1].length + 2; html += '<h' + lvl + ' class="md-h">' + h[2] + '</h' + lvl + '>'; continue; }
        if (ln.trim() === '') { continue; }
        html += '<p>' + ln + '</p>';
      }
      if (inList) { html += '</ul>'; }
      html = html.replace(/<p>�B(\d+)�<\/p>/g, function (_, i) { return blocks[i]; });
      html = html.replace(/�B(\d+)�/g, function (_, i) { return blocks[i]; });
      return html;
    }
    // Every message is a Milpa Component (greenhouse decisions/0191): the conversation CLONES the prototype for
    // its kind and fills the instance's data regions — no more createElement. The backend's stream routes here
    // by event type; the user's own message uses it too. (Running the turn is the agent runtime, decisions/0254.)
    var MSG_PROTOS = {
      agent: { id: 'milpa-agent-msg-proto', fill: function (r, o) { var b = r.querySelector('[data-agent-body]'); if (b) { b.innerHTML = renderMarkdown(o.text || ''); } } },
      user: { id: 'milpa-user-msg-proto', fill: function (r, o) { var b = r.querySelector('[data-user-body]'); if (b) { b.textContent = o.text || ''; } } },
      tool: { id: 'milpa-tool-msg-proto', fill: function (r, o) {
        var n = r.querySelector('[data-tool-name]'); if (n) { n.textContent = o.name || 'tool'; }
        var raw = String(o.result || '');
        var sum = r.querySelector('[data-tool-summary]'); if (sum) { sum.textContent = toolSummary(raw); }
        var body = r.querySelector('[data-tool-body]'); if (body) { body.textContent = prettyMaybe(raw); }
      } },
      task: { id: 'milpa-task-msg-proto', fill: function (r, o) { var t = r.querySelector('[data-task-title]'); if (t) { t.textContent = o.title || ''; } var s = r.querySelector('[data-task-status]'); if (s) { s.textContent = o.status || 'todo'; } } },
      system: { id: 'milpa-system-msg-proto', fill: function (r, o) { var b = r.querySelector('[data-system-body]'); if (b) { b.textContent = o.text || ''; } } },
      result: { id: 'milpa-result-msg-proto', fill: function (r, o) {
        var ok = o.verified !== false;
        r.setAttribute('data-verified', ok ? '1' : '0');
        var mark = r.querySelector('[data-result-mark]'); if (mark) { mark.textContent = ok ? '✓' : '⚠'; }
        var txt = r.querySelector('[data-result-text]'); if (txt) { txt.textContent = ok ? 'verified' : 'disputed'; }
        // The tooltip carries WHAT the ledger judged — the reasons go here now, not inline (Rod's minimalism).
        var tip = ok
          ? "The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact's latest check is red."
          : ('The ledger disputes this turn — ' + (o.reasons ? o.reasons : 'the completion is not backed by evidence') + '.');
        var te = r.querySelector('[data-result-tip]'); if (te) { te.textContent = tip; }
        r.setAttribute('aria-label', (ok ? 'Verified. ' : 'Disputed. ') + tip);
      } }
    };
    // A tool result's one-line summary (a count for JSON, a truncation otherwise); the raw sits in the
    // collapsible body below. Milpa Components render the machinery legibly, not as a raw dump (Rod).
    function toolSummary(raw) {
      if (!raw) { return ''; }
      try {
        var j = JSON.parse(raw);
        if (Array.isArray(j)) { return '→ ' + j.length + ' items'; }
        if (j && typeof j === 'object') { return typeof j.ok !== 'undefined' ? ('→ ok · ' + Object.keys(j).length + ' fields') : ('→ ' + Object.keys(j).length + ' fields'); }
      } catch (e) {}
      return '→ ' + (raw.length > 60 ? raw.slice(0, 60) + '…' : raw);
    }
    function prettyMaybe(raw) {
      try { return JSON.stringify(JSON.parse(raw), null, 2); } catch (e) { return raw; }
    }
    function appendMessage(kind, opts) {
      if (!chat) { return; }
      opts = opts || {};
      // A discrete "thinking" message reuses the thinking component (streamed thinking uses appendReasoning).
      if (kind === 'thinking') { appendReasoning(opts.text || ''); endReasoning(); return; }
      var spec = MSG_PROTOS[kind] || MSG_PROTOS.system;
      var proto = document.getElementById(spec.id);
      if (!proto || !('content' in proto)) { return; }
      var frag = proto.content.cloneNode(true);
      var root = frag.querySelector('.msg');
      if (root) { spec.fill(root, opts); }
      chat.appendChild(frag);
      if (root) { root.scrollIntoView({ block: 'end' }); }
      return root;
    }

    // The thinking block is the `desktop-thinking` Milpa Component (greenhouse decisions/0191): the conversation
    // CLONES its server-rendered prototype per turn and feeds THIS instance — the reasoning into its body, the
    // elapsed into its head. Its collapse is the component's own (CSS on `data-open`); one delegated toggle
    // handler (below) flips it. No per-instance imperative wiring, no reliance on Alpine hydrating a clone.
    var reasoningEl = null, reasoningStart = 0;
    function appendReasoning(delta) {
      if (!chat) { return; }
      if (!reasoningEl) {
        var proto = document.getElementById('milpa-thinking-proto');
        if (!proto || !('content' in proto)) { return; }
        var frag = proto.content.cloneNode(true);
        reasoningEl = frag.querySelector('.milpa-think');
        reasoningStart = Date.now();
        chat.appendChild(frag);
      }
      if (reasoningEl) {
        var body = reasoningEl.querySelector('[data-thinking-body]');
        if (body) { body.textContent += (delta || ''); }
        reasoningEl.scrollIntoView({ block: 'end' });
      }
    }
    function endReasoning() {
      if (!reasoningEl) { return; }
      var secs = Math.max(1, Math.round((Date.now() - reasoningStart) / 1000));
      // Replace only the LABEL words — the animated spark/dots are the component's own and must survive.
      var label = reasoningEl.querySelector('[data-thinking-label]');
      if (label) { label.textContent = 'thought for ' + secs + 's'; }
      else { var head = reasoningEl.querySelector('[data-thinking-head]'); if (head) { head.textContent = '◈ thought for ' + secs + 's'; } }
      reasoningEl.setAttribute('data-thinking-active', '0'); // stop the pulse: the reasoning is done
      reasoningEl.setAttribute('data-open', '0');
      reasoningEl = null;
    }
    // The closure verdict RIDES the last agent answer's tool row (Rod's ask — saves a whole line). Returns
    // false when there's no answer to ride, so the caller can fall back to the standalone result-claim line.
    function markAgentVerdict(verified, reasons) {
      if (!chat) { return false; }
      var msgs = chat.querySelectorAll('.msg--agent');
      var last = msgs.length ? msgs[msgs.length - 1] : null;
      var slot = last ? last.querySelector('[data-agent-verdict]') : null;
      if (!slot) { return false; }
      var ok = verified !== false;
      slot.setAttribute('data-verified', ok ? '1' : '0');
      var mark = slot.querySelector('[data-verdict-mark]'); if (mark) { mark.textContent = ok ? '✓' : '⚠'; }
      var lbl = slot.querySelector('[data-verdict-label]'); if (lbl) { lbl.textContent = ok ? 'verified' : 'disputed'; }
      var tip = ok
        ? "The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact's latest check is red."
        : ('The ledger disputes this turn — ' + (reasons ? reasons : 'the completion is not backed by evidence') + '.');
      var te = slot.querySelector('[data-verdict-tip]'); if (te) { te.textContent = tip; }
      slot.setAttribute('aria-label', (ok ? 'Verified. ' : 'Disputed. ') + tip);
      slot.hidden = false;
      return true;
    }
    // One set of delegated handlers for every message component, now and future (greenhouse decisions/0191):
    // the thinking block's toggle, and the agent message's foot tools (copy the answer, regenerate it).
    if (chat) {
      chat.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }
        var toggle = e.target.closest('[data-thinking-toggle]');
        if (toggle) {
          var block = toggle.closest('.milpa-think');
          if (block) { block.setAttribute('data-open', block.getAttribute('data-open') === '1' ? '0' : '1'); }
          return;
        }
        var toolToggle = e.target.closest('[data-tool-toggle]');
        if (toolToggle) {
          var tool = toolToggle.closest('.msg--tool');
          if (tool) { tool.setAttribute('data-open', tool.getAttribute('data-open') === '1' ? '0' : '1'); }
          return;
        }
        var copyBtn = e.target.closest('[data-agent-copy]');
        if (copyBtn) {
          var msg = copyBtn.closest('.msg--agent');
          var bodyEl = msg && msg.querySelector('[data-agent-body]');
          var textToCopy = bodyEl ? bodyEl.textContent : '';
          if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(textToCopy).catch(function () {}); }
          copyBtn.classList.add('is-done');
          setTimeout(function () { copyBtn.classList.remove('is-done'); }, 1200);
          return;
        }
        var regenBtn = e.target.closest('[data-agent-regenerate]');
        if (regenBtn) {
          if (lastPrompt) { runTurn(lastPrompt); }
          return;
        }
      });
    }

    // The agent session this Desktop drives (greenhouse decisions/0190): the server minted it, set it in a
    // cookie, and scoped the hub JWT + the EventSource to its exact stream topic — so a governed turn's
    // session.* events reach this shell live.
    var agentSession = '<!--AGENTSID-->';

    // The last prompt sent — so the agent message's Regenerate tool can re-run the same turn.
    var lastPrompt = '';
    // Start a governed turn over the HTTP surface (greenhouse decisions/0190). The working/idle badge and the
    // reasoning stream arrive live over the hub on this session's topic; the final answer comes back here.
    // `mode: ask` keeps mutating tools behind their gate.
    function runTurn(text) {
      lastPrompt = text;
      fetch('/agent', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: text, session: agentSession, mode: 'ask' })
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.ok && res.answer) { appendMessage('agent', { text: res.answer }); }
        else if (res && res.paused) { appendMessage('system', { text: res.hint || 'The agent is waiting on your decision.' }); }
        else if (res && res.error) { appendMessage('system', { text: res.error }); }
        // The closure verdict as a result-claim message (greenhouse decisions/0191, evidence/0442): the ledger
        // either backs the answer or disputes it. Only show it when the house actually judged the turn.
        if (res && res.closure) {
          var v = res.closure.verified !== false, why = (res.closure.reasons || []).join('; ');
          // Primary: ride the answer's tool row (saves a line). Fallback: the standalone result-claim message.
          if (!markAgentVerdict(v, why)) { appendMessage('result', { verified: v, reasons: why }); }
        }
        // Update the shared counters from what the turn reported (greenhouse decisions/0191): one truth, and
        // every place that shows turns/steps/tools/tokens — the composer chips, the status bar, the panels —
        // is a projection of these signals, so they all move at once.
        if (res && res.ok) { updateCounters(res); }
      }).catch(function () { appendMessage('system', { text: 'The turn could not be reached.' }); });
    }
    // The counters as signals, updated from the turn and the stream — the single source projected everywhere.
    function sig(key) { return (window.MilpaLive && MilpaLive.signal) ? (MilpaLive.signal(key) || 0) : 0; }
    function setSig(key, val) { if (window.MilpaLive && MilpaLive.signal) { MilpaLive.signal(key, val); } }
    function kfmt(n) { return (n / 1000).toFixed(2) + 'K'; }
    function updateCounters(res) {
      setSig('session.turns', (parseInt(sig('session.turns'), 10) || 0) + 1);
      if (typeof res.steps === 'number') { setSig('session.steps', (parseInt(sig('session.steps'), 10) || 0) + res.steps); }
      // The REAL token cost, from the provider's own numbers the turn reported (greenhouse decisions/0192) —
      // a counted figure, not an estimate. `tokens` is the session's cumulative total; `contextTokens` is what the last call
      // put in the window. Absent when the provider never said (the op omits it), so the seed stands rather
      // than a fabricated zero — the house's own doctrine (SessionEvent::ModelReturned): a token bar that
      // guesses is a fabricated number in a real one's clothes. One truth, projected to the status bar and chip.
      if (typeof res.tokens === 'number') { setSig('session.tokens', kfmt(res.tokens)); }
      if (typeof res.contextTokens === 'number') { setSig('context.used', kfmt(res.contextTokens)); }
    }
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
      runTurn(text);
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
<script id="milpa-live-computed" type="application/json">{"session.summary":{"template":"{session.state.label} · {session.turns} turns"},"session.counters":{"template":"{session.turns} turns · {session.tool_calls} tools"},"context.usage":{"template":"{context.used}/{context.window}"},"session.status":{"template":"{session.turns} turns · {session.steps} steps · {session.tokens} tokens · {session.tool_calls} tool calls"}}</script>
<script src="/desktop/assets/milpa-live.js"></script>
<script src="/desktop/assets/milpa-live-remote.js"></script>
<script src="/desktop/assets/alpine.min.js"></script>
</body>
</html>
HTML;
    }
}
