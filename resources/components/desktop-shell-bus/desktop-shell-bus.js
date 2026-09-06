/*!
 * desktop-shell-bus — the Desktop's event bus, as a client module (greenhouse decisions/0211, phase D1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * NOT a component: it registers no Alpine factory, because a bus is not a surface — nothing renders it.
 * Like `desktop-guard` it is a RUNTIME module the page declares, emitted once by `LiveBoot::html()`
 * immediately after the guard and before every module that subscribes to it.
 *
 * WHY ITS OWN MODULE, and not folded into the conversation or the turn (the question phase D asked):
 * `window.MilpaShell` is the Desktop's PUBLISHED extension point, not a private channel between two
 * modules. Four shipped modules already consume it (`desktop-conversation`, `desktop-turn`,
 * `desktop-activity`, `desktop-gate`), and a plugin that contributes a panel drives it live through
 * `MilpaShell.panel('<id>')` ({@see \Milpa\DesktopApp\ShellComposition}). A channel with five consumers
 * and a documented API is a module; folding it into one of its consumers would make the other four
 * depend on that one.
 *
 * What it owns:
 *   - `on(type, cb)` / `onAny(cb)` / `emit(type, data)` — the shell's own facts, by type;
 *   - `onStatus(cb)` / `status(state)` — the transport's state. `status()` is also where the connection
 *     becomes SIGNALS (`conn.state`, `conn.label`), so the status bar BINDS to it instead of anything
 *     reaching for `#milpa-conn` (greenhouse decisions/0211, D4);
 *   - `panel(id)` — a contributed panel's body, the plugin DX.
 *
 * What it does NOT own: the transport (that is `desktop-hub`, which opens the EventSource and translates
 * hub envelopes into these facts) and any DOM but a panel's body.
 */
(function () {
  'use strict';

  var live = window.MilpaLive || null;
  if (window.MilpaShell) {
    if (window.console && console.warn) { console.warn('[desktop-shell-bus] loaded twice; ignoring the second copy'); }

    return;
  }

  function desk() { return (live && live.desktop) || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  function signal(key, value) {
    if (!live || typeof live.signal !== 'function') { return null; }

    return arguments.length > 1 ? live.signal(key, value) : live.signal(key);
  }

  var byType = {};
  var anyHandlers = [];
  var statusHandlers = [];

  /** Subscribe to one fact type. */
  function on(type, cb) {
    if (typeof cb !== 'function') { return; }
    (byType[type] = byType[type] || []).push(cb);
  }

  /** Subscribe to EVERY fact — what the Activity tab projects. */
  function onAny(cb) { if (typeof cb === 'function') { anyHandlers.push(cb); } }

  /** Subscribe to the transport's state. */
  function onStatus(cb) { if (typeof cb === 'function') { statusHandlers.push(cb); } }

  /** Publish one fact: to its own subscribers first, then to the any-handlers. */
  function emit(type, data) {
    var typed = byType[type] || [];
    for (var i = 0; i < typed.length; i++) {
      try { typed[i](data); } catch (e) { /* one deaf consumer never silences the rest */ }
    }
    for (var j = 0; j < anyHandlers.length; j++) {
      try { anyHandlers[j](type, data); } catch (e) { /* idem */ }
    }
  }

  /**
   * The transport's state, said ONCE and projected everywhere.
   *
   * The status bar used to be poked here by id; it binds `conn.state` and `conn.label` now, so a second
   * surface that wants to show the connection binds too instead of being poked.
   */
  function status(state) {
    var key = state === 'live' ? 'conn.live' : (state === 'connecting' ? 'conn.connecting' : 'conn.offline');
    signal('conn.state', state === 'live' ? 'live' : (state === 'connecting' ? 'connecting' : 'offline'));
    signal('conn.label', tr(key));
    for (var i = 0; i < statusHandlers.length; i++) {
      try { statusHandlers[i](state); } catch (e) { /* idem */ }
    }
  }

  /** A contributed panel's body — the plugin DX (`addPanel()` on the server, `panel()` here). */
  function panel(id) {
    var host = document.querySelector('[data-panel="' + String(id) + '"]');

    return host ? host.querySelector('[data-panel-body]') : null;
  }

  window.MilpaShell = { on: on, onAny: onAny, onStatus: onStatus, emit: emit, status: status, panel: panel };

  if (live && live.desktop) { live.desktop.bus = window.MilpaShell; }
})();
