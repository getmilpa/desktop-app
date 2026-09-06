/*!
 * desktop-auth — the Desktop's entry overlay, as a client module (greenhouse decisions/0211, phase B5).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the overlay's renderer through DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 * It registers ONE Alpine factory, `desktopAuth`, bound by the overlay with `x-data="desktopAuth()"`.
 *
 * The overlay's visibility is the shared `desktop.auth.open` signal, so opening it is one signal set and
 * every surface that offers «New session» — the sidebar's button, the embed strip's control — runs the
 * SAME ceremony through `MilpaLive.desktop.auth.open()`.
 *
 * `enter()` creates the session (`POST /desktop/sessions`) THROUGH THE GUARD: a door's 401 leaves for
 * sign-in and comes back, a 403 is told once, and only a 2xx reloads into the new session. When the door
 * answered instead of the handler the overlay closes first, so the notice is in view and not behind it.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-auth] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopAuth')) {
    if (window.console && console.warn) { console.warn('[desktop-auth] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The shared signal the overlay's visibility is (the server seeds it false). */
  var OPEN_SIGNAL = 'desktop.auth.open';

  function desk() { return live.desktop || null; }
  function tr(key, arg) { var d = desk(); return d ? d.tr(key, arg) : key; }
  function setOpen(open) { if (typeof live.signal === 'function') { live.signal(OPEN_SIGNAL, open); } }

  live.register('desktopAuth', function () {
    return {
      /** The overlay's visibility IS the shared signal — one truth, so any surface can ask for it. */
      get open() {
        return this.$store.milpa[OPEN_SIGNAL] === true;
      },
      /** Create the session, then reload into it. «Opened» is only ever said on a 2xx. */
      enter: function () {
        var d = desk();
        if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
        var app = document.getElementById('auth-app');
        var self = this;

        return fetch('/desktop/sessions', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ goal: 'New session · ' + (app ? app.value : '') }),
        }).then(d.guarded).then(function () {
          location.reload();
        }).catch(function (err) {
          // The door answered instead of the handler: close the overlay so the notice is in view.
          self.$store.milpa[OPEN_SIGNAL] = false;
          d.failed(err, tr('guard.unreachable'));
        });
      },
      /** Close without creating anything — nothing ran on open, so nothing is undone. */
      dismiss: function () {
        this.$store.milpa[OPEN_SIGNAL] = false;
      },
    };
  });

  // The window chrome's «Open workspace» is this component's own entry point, so this module wires it.
  var opener = document.getElementById('milpa-auth-open');
  if (opener) { opener.addEventListener('click', function () { setOpen(true); }); }

  // The ceremony every other surface asks for: one implementation, no copies (greenhouse decisions/0210).
  if (live.desktop) {
    live.desktop.auth = {
      open: function () { setOpen(true); },
      close: function () { setOpen(false); },
    };
  }
})();
