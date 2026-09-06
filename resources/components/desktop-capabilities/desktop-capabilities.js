/*!
 * desktop-capabilities — the Capabilities screen's behaviour (greenhouse decisions/0211, phase D2).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the screen's renderer ({@see \Milpa\DesktopApp\Live\CapabilitiesScreen}) through
 * DeclaresClientAssets and emitted, once, by `LiveBoot::html()`. It registers ONE Alpine factory,
 * `desktopCapabilities`, which the screen's root binds with
 * `x-data="desktopCapabilities()" @click="onClick($event)"`.
 *
 * It owns the TWO-STEP install and nothing else (greenhouse decisions/0193): the first `POST` to the
 * capability operation's HTTP projection may answer with the house's confirm gate — `428` plus a
 * `Confirm-Token` — and the second call carries that token back. That 428 is the FLOW, not a refusal, so
 * it passes `guardedFlow`; a 401/403 does not, and would be handled by the shared guard exactly as
 * everywhere else (greenhouse decisions/0209).
 *
 * WHAT THIS DOOR ACTUALLY GATES, measured: `capabilities:enable` carries no scope of its own, so on an app
 * that exposes it over HTTP the confirm token is the ONLY thing between a same-origin request and a
 * `composer require` on the host — the door answers a 428 and then a 201 with no session at all. That is
 * the runtime's to fix (the operation needs a permission the HTTP policy can gate); this module cannot,
 * and does not pretend the guard is covering it.
 *
 * The confirm box is a SERVER-RENDERED prototype (`#milpa-cap-confirm-proto`), cloned and filled by
 * `textContent`, the way a conversation message kind lands. Nothing here writes markup: the page's inline
 * script used to build that box with `innerHTML`, which put a piece of the UI in a JavaScript string
 * where no renderer, no test and no plugin could reach it.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-capabilities] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopCapabilities')) {
    if (window.console && console.warn) { console.warn('[desktop-capabilities] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The capability operation's own HTTP projection (greenhouse decisions/0193). */
  var ROUTE = '/capabilities/enable';
  /** The confirm box the server rendered once, for this module to clone per card. */
  var PROTO = 'milpa-cap-confirm-proto';
  /** How long the "installed" line stands before the page is reloaded with the new capability. */
  var RELOAD_MS = 900;

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  /** The two-step, as ONE promise that always resolves to `{ok, error}` — never rejects on the caller. */
  function enable(pkg) {
    var d = desk();
    if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
    var body = JSON.stringify({ capability: pkg });

    return fetch(ROUTE, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body })
      .then(d.guardedFlow)
      .then(function (response) { return response.json(); })
      .then(function (answer) {
        if (!answer || !answer.confirm_token) {
          return (answer && answer.ok) ? answer : { ok: false, error: (answer && answer.error) || tr('cap.no_token') };
        }

        return fetch(ROUTE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Confirm-Token': answer.confirm_token },
          body: body,
        }).then(d.guarded).then(function (confirmed) {
          return confirmed.json().then(function (read) {
            return (read && typeof read.ok === 'boolean') ? read : { ok: confirmed.ok, error: read && read.error };
          });
        });
      })
      .catch(function (err) {
        return { ok: false, error: (err && err.body && err.body.error) || (err && err.status ? tr('guard.failed', err.status) : tr('cap.refused')) };
      });
  }

  /** Clone the prototype into the card the Enable button belongs to, showing the exact command. */
  function open(button) {
    var card = button.closest('.cap-card');
    var proto = document.getElementById(PROTO);
    if (!card || !proto || !('content' in proto) || card.querySelector('.cap-confirm')) { return null; }
    var frag = proto.content.cloneNode(true);
    var box = frag.querySelector('.cap-confirm');
    if (!box) { return null; }
    box.setAttribute('data-cap-package', button.getAttribute('data-cap-enable') || '');
    var command = box.querySelector('[data-cap-cmd-text]');
    if (command) { command.textContent = button.getAttribute('data-cap-cmd') || ''; }
    button.disabled = true;
    card.appendChild(frag);

    return box;
  }

  /** Take the box back down and give the card its Enable button back. */
  function close(box) {
    if (!box) { return; }
    var card = box.closest('.cap-card');
    var button = card ? card.querySelector('[data-cap-enable]') : null;
    if (button) { button.disabled = false; }
    if (typeof box.remove === 'function') { box.remove(); } else if (box.parentNode) { box.parentNode.removeChild(box); }
  }

  /** Run the two-step and REPORT what the house answered, in the box the human is already reading. */
  function run(box) {
    var pkg = box.getAttribute('data-cap-package') || '';
    var go = box.querySelector('[data-cap-go]');
    var line = box.querySelector('[data-cap-cmd-text]');
    var row = box.querySelector('[data-cap-actions]');
    if (go) { go.disabled = true; go.textContent = tr('cap.working'); }

    return enable(pkg).then(function (result) {
      if (row) {
        if (typeof row.remove === 'function') { row.remove(); } else if (row.parentNode) { row.parentNode.removeChild(row); }
      }
      var ok = !!(result && result.ok);
      if (line) { line.textContent = ok ? tr('cap.done', pkg) : tr('cap.failed', pkg, (result && result.error) || tr('cap.refused')); }
      if (ok) { setTimeout(function () { location.reload(); }, RELOAD_MS); }

      return result;
    });
  }

  live.register('desktopCapabilities', function () {
    return {
      /** The screen's ONE click handler: open the confirm box, cancel it, or run the two-step. */
      onClick: function (event) {
        var target = event && event.target;
        if (!target || typeof target.closest !== 'function') { return false; }
        var enableButton = target.closest('[data-cap-enable]');
        if (enableButton) { open(enableButton); return true; }
        var cancel = target.closest('[data-cap-cancel]');
        if (cancel) { close(cancel.closest('.cap-confirm')); return true; }
        var go = target.closest('[data-cap-go]');
        if (go) { run(go.closest('.cap-confirm')); return true; }

        return false;
      },
    };
  });

  if (live.desktop) {
    live.desktop.capabilities = { enable: enable, open: open, close: close, run: run };
  }
})();
