/*!
 * desktop-guard — the Desktop's shared client runtime module (greenhouse decisions/0211, phase A4).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * NOT a component: it registers no Alpine factory. It is the one place the Desktop's fetch discipline,
 * its copy and its cross-surface signals live, hung off the framework runtime as `MilpaLive.desktop` so
 * every Desktop module reaches the SAME guard instead of each carrying its own copy. It is declared as a
 * client asset of the page (the shell puts it first in the declared scripts) and emitted, once, by
 * `LiveBoot::html()` — after `milpa-live.js`, before any component module.
 *
 * What it owns:
 *   - `tr(key, arg)` — the Desktop's copy in the declared locale, read once from `#milpa-desktop-i18n`.
 *   - `guarded(response)` — every Desktop fetch() result passes through here: a 401 is the passkey gate
 *     asking for a session (go to the door the body named, or the app's declared one from
 *     `#milpa-desktop-guard`, and come back through `next`; the promise never settles, so no caller paints
 *     over a page that is leaving), a 403 is told once as a notice and rejected, any other non-2xx rejects
 *     with its status and parsed body — so «Saved» is only ever said on a 2xx.
 *   - `guardedFlow(response)` — the same discipline with 428 passed through: the house's confirm gate is
 *     the capabilities FLOW, not a refusal.
 *   - `failed(err, unreachable)` — a rejected call told once (never twice for a 403 `guarded` already told).
 *   - `notice(kind, text)` — the `desktop.notice` signal (payload `{kind, text}`): the guard no longer
 *     reaches into the conversation, it says what happened and whoever renders notices consumes it.
 *   - `onDismiss(cb)` — ONE document-level click listener bumps the `ui.dismiss` signal and calls every
 *     consumer with the event; the mode menu and the command popup each register one instead of both
 *     hanging their own listener on `document`.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live) {
    // The shell emits this module through LiveBoot, after the runtime; without it there is nothing to hang on.
    if (window.console && console.warn) { console.warn('[desktop-guard] the milpa/live runtime must load first'); }
    return;
  }
  if (live.desktop) {
    if (window.console && console.warn) { console.warn('[desktop-guard] loaded twice; ignoring the second copy'); }
    return;
  }

  // The Desktop's copy, in the declared locale (greenhouse decisions/0209): the server hands it over as
  // JSON (#milpa-desktop-i18n) so the client says the same words. A key nobody wrote answers as itself.
  var I18N = (function () {
    var el = document.getElementById('milpa-desktop-i18n');
    try { var v = el ? JSON.parse(el.textContent || '{}') : {}; return (v && typeof v === 'object') ? v : {}; } catch (e) { return {}; }
  })();

  // One `%s` per argument, in order: the copy the conversation and the commands say carries two and three
  // («%s → HTTP %s — %s»), and a sentence assembled out of fragments cannot be translated as a sentence.
  function tr(key, arg) {
    var s = Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : key;
    if (typeof arg === 'undefined') { return s; }
    var args = Array.prototype.slice.call(arguments, 1);
    for (var i = 0; i < args.length; i++) { s = String(s).replace('%s', String(args[i])); }

    return s;
  }

  // The door this app signs in at (`#milpa-desktop-guard`), written by the shell as DATA and only when the
  // Desktop really stands behind the passkey gate. It is the FALLBACK for a 401 whose BODY names none: the
  // Desktop's own doors answer `{"signin":"…"}`, but app-runtime's operation doors (`/agent`, `/agent/goal`,
  // `/skill/invoke`) answer a bare `MILPA_UNAUTHENTICATED`. Without it a session that expired mid-page left
  // the human reading a runtime error with no way back in; with it, every 401 is the same door.
  var DOORS = (function () {
    var el = document.getElementById('milpa-desktop-guard');
    try { var v = el ? JSON.parse(el.textContent || '{}') : {}; return (v && typeof v === 'object') ? v : {}; } catch (e) { return {}; }
  })();

  /** Where a 401 sends the human: what the door itself said, else the app's declared sign-in path. */
  function signinFor(body) {
    if (body && typeof body.signin === 'string' && body.signin !== '') { return body.signin; }

    return typeof DOORS.signin === 'string' ? DOORS.signin : '';
  }

  function signal(key, value) {
    if (typeof live.signal !== 'function') { return null; }
    return arguments.length > 1 ? live.signal(key, value) : live.signal(key);
  }

  // ── desktop.notice ───────────────────────────────────────────────────────────────────────────────
  // The coupling that was `notice(text) → appendMessage('system', …)` is now a signal: the guard says
  // WHAT happened and never touches the conversation. Consumers register with onNotice().
  var noticeHandlers = [];
  var noticeSeq = 0;

  function notice(kind, text) {
    var payload = { kind: String(kind || 'system'), text: String(text == null ? '' : text), seq: ++noticeSeq };
    signal('desktop.notice', payload);
    for (var i = 0; i < noticeHandlers.length; i++) {
      try { noticeHandlers[i](payload); } catch (e) { /* one deaf consumer never silences the rest */ }
    }
    return payload;
  }

  function onNotice(cb) { if (typeof cb === 'function') { noticeHandlers.push(cb); } }

  // ── the fetch discipline ─────────────────────────────────────────────────────────────────────────
  function reject(r) {
    return r.text().then(function (t) {
      var body = null;
      try { body = JSON.parse(t); } catch (e) { /* a door may answer HTML */ }
      body = (body && typeof body === 'object') ? body : {};
      var signin = r.status === 401 ? signinFor(body) : '';
      if (signin !== '') {
        location.assign(signin + '?next=' + encodeURIComponent(location.pathname + location.search));
        return new Promise(function () {});
      }
      var err = new Error('HTTP ' + r.status);
      err.status = r.status;
      err.body = body;
      err.told = false;
      if (r.status === 403) {
        notice('error', (typeof body.error === 'string' && body.error !== '') ? tr('guard.forbidden.reason', body.error) : tr('guard.forbidden'));
        err.told = true;
      }
      return Promise.reject(err);
    });
  }

  function guarded(r) {
    if (r.ok) { return Promise.resolve(r); }
    return reject(r);
  }

  // The capabilities two-step (greenhouse decisions/0193): the first call may answer with the house's
  // confirm gate (428 + the token). That is the flow, not a refusal, so it passes; a door's 401/403 does not.
  function guardedFlow(r) {
    if (r.ok || r.status === 428) { return Promise.resolve(r); }
    return reject(r);
  }

  // A rejected call, told once: a 403 was already told by guarded(); a status error names the body's error
  // or the status; anything else — the network — says what the caller passed, if anything.
  function failed(err, unreachable) {
    if (err && err.told) { return; }
    if (err && err.status) {
      notice('error', (err.body && typeof err.body.error === 'string' && err.body.error !== '') ? err.body.error : tr('guard.failed', err.status));
      return;
    }
    if (unreachable) { notice('error', unreachable); }
  }

  // ── ui.dismiss ───────────────────────────────────────────────────────────────────────────────────
  // ONE document listener, many consumers: the click-away that used to live inside the mode menu's own
  // wiring is a signal now, so any surface can close on it without adding a listener of its own.
  var dismissHandlers = [];
  var dismissSeq = 0;

  function onDismiss(cb) { if (typeof cb === 'function') { dismissHandlers.push(cb); } }

  document.addEventListener('click', function (event) {
    signal('ui.dismiss', ++dismissSeq);
    for (var i = 0; i < dismissHandlers.length; i++) {
      try { dismissHandlers[i](event); } catch (e) { /* one deaf consumer never silences the rest */ }
    }
  });

  live.desktop = {
    tr: tr,
    signal: signal,
    guarded: guarded,
    guardedFlow: guardedFlow,
    failed: failed,
    notice: notice,
    onNotice: onNotice,
    onDismiss: onDismiss,
  };
})();
