/*!
 * desktop-sidebar — the Desktop shell's sidebar, as a client module (greenhouse decisions/0211, phase B5).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the sidebar's renderer through DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 * It registers ONE Alpine factory, `desktopSidebar`, bound by the `<nav>` with
 * `x-data="desktopSidebar({ active })"`, and owns every reach of the sidebar:
 *
 *   - `go(key)` sets the shared `desktop.nav` signal (the highlight is a BINDING on it) and swaps the
 *     main between the views the same key names — one verb, not a click handler per item;
 *   - `newSession()` asks the AUTH module to open its overlay: creating a session is that component's
 *     ceremony, so there is one implementation and the session strip's own control runs the same one
 *     (greenhouse decisions/0210, B8);
 *   - `enroll(event)` probes the passkey door before navigating: a 404 (or no answer) degrades the link
 *     in place instead of replacing the whole app with a 404 page; a 401/403 is a door, not an absence.
 *
 * Two controls the sidebar reaches for live OUTSIDE its root and are wired here because they drive it:
 * the window chrome's session search (`#milpa-search`), and — in embed mode, where the sidebar is folded
 * — the session strip's picker (`#milpa-embed-session`) and its «New session» button
 * (`[data-new-session]`), which is why both surfaces call this module rather than each carrying a copy.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-sidebar] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopSidebar')) {
    if (window.console && console.warn) { console.warn('[desktop-sidebar] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The shared signal the active nav item is. */
  var NAV_SIGNAL = 'desktop.nav';
  /** Which view each nav key opens; anything unknown falls back to the session view. */
  var NAV_TO_VIEW = { sessions: 'session', decisions: 'decisions', capabilities: 'capabilities', skills: 'skills', preview: 'preview', settings: 'settings' };

  function desk() { return live.desktop || null; }
  function tr(key, arg) { var d = desk(); return d ? d.tr(key, arg) : key; }

  /**
   * Swap the main between its views — the same shell, not a window. The auth overlay is a `.view` too but
   * opens on demand, so navigation never touches it.
   */
  function showView(view) {
    var views = document.querySelectorAll('.view');
    for (var i = 0; i < views.length; i++) {
      if (views[i].getAttribute('data-view') !== 'auth') {
        views[i].hidden = views[i].getAttribute('data-view') !== view;
      }
    }
  }

  /** Filter the rendered session list by goal text — client-side over what the server already painted. */
  function filterSessions(query) {
    var needle = String(query || '').trim().toLowerCase();
    var items = document.querySelectorAll('.milpa-session-item');
    for (var i = 0; i < items.length; i++) {
      var goalEl = items[i].querySelector('.milpa-session-goal');
      var goal = goalEl ? goalEl.textContent.toLowerCase() : '';
      items[i].classList.toggle('milpa-search-miss', needle !== '' && goal.indexOf(needle) === -1);
    }
  }

  /** Open the new-session ceremony — the auth overlay's, wherever the request came from. */
  function newSession() {
    var d = desk();
    if (d && d.auth && typeof d.auth.open === 'function') { d.auth.open(); }
  }

  live.register('desktopSidebar', function (config) {
    var options = config || {};

    return {
      /** The nav the server painted as current — the value before the store answers. */
      fallback: typeof options.active === 'string' ? options.active : 'sessions',
      /** Navigate: the highlight is the signal, the main swaps to the view the key names. */
      go: function (key) {
        this.$store.milpa[NAV_SIGNAL] = key;
        showView(NAV_TO_VIEW[key] || 'session');
      },
      /** Whether `key` is the current nav — read INSIDE the effect, so aria-current stays reactive. */
      isCurrent: function (key) {
        var current = this.$store.milpa[NAV_SIGNAL];

        return (current === undefined || current === null ? this.fallback : current) === key;
      },
      /** «New session» — the sidebar's button and the embed strip's run this same one. */
      newSession: function () {
        newSession();
      },
      /**
       * Probe the passkey door before leaving for it: only an app that actually mounts it navigates.
       * A 401 (guarded() is already leaving for sign-in) or a 403 is a door refusing, not a door missing.
       */
      enroll: function (event) {
        var link = event && event.currentTarget ? event.currentTarget : document.getElementById('milpa-enroll-link');
        var d = desk();
        if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }

        return fetch('/webauthn/enroll', { method: 'GET' }).then(d.guarded).then(function () {
          location.href = '/webauthn/enroll';
        }).catch(function (err) {
          if (err && err.status && err.status !== 404) { d.failed(err); return; }
          if (link) {
            link.textContent = tr('enroll.none');
            link.setAttribute('aria-disabled', 'true');
            link.classList.add('milpa-enroll--absent');
          }
        });
      },
    };
  });

  // The window chrome's search filters the sidebar's own list, so the sidebar owns it.
  var search = document.getElementById('milpa-search');
  if (search) { search.addEventListener('input', function () { filterSessions(search.value); }); }

  // Embed mode (greenhouse decisions/0210): the strip's controls are the folded sidebar's reach — the
  // SAME handlers, wired here, so neither surface carries a second copy of them.
  var pickers = document.querySelectorAll('[data-new-session]');
  for (var i = 0; i < pickers.length; i++) { pickers[i].addEventListener('click', newSession); }
  var pick = document.getElementById('milpa-embed-session');
  if (pick) {
    pick.addEventListener('change', function () {
      if (pick.value !== '') { location.assign('?session=' + encodeURIComponent(pick.value) + '&embed=1'); }
    });
  }

  if (live.desktop) { live.desktop.sidebar = { showView: showView, filterSessions: filterSessions, newSession: newSession }; }
})();
