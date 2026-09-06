/*!
 * desktop-screens — the declared-screen preview (greenhouse decisions/0211, phase D4).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the preview's renderer ({@see \Milpa\DesktopApp\Live\ScreenPreview}) through
 * DeclaresClientAssets and emitted, once, by `LiveBoot::html()`. It registers ONE Alpine factory,
 * `desktopScreens`, which the screen's root binds with
 * `x-data="desktopScreens()" @click="onClick($event)" @keydown="onKey($event)"`.
 *
 * "How does it look?", answered (greenhouse decisions/0197): a screen the agent declared is served live by
 * the wire — same origin, so its own runtime and Alpine boot INSIDE the frame — and this points the iframe
 * at it. A chip carries the exact path the wire serves it at; the manual box builds one from the live
 * route the server wrote onto the Preview button and the name that was typed.
 *
 * The route is read from the markup this module was given, never from a global: a preview that cannot
 * find its own button previews nothing rather than guessing a path. And the wire is ASKED before the
 * frame is pointed at it — a name the wire does not serve is reported, not left as a blank frame.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-screens] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopScreens')) {
    if (window.console && console.warn) { console.warn('[desktop-screens] loaded twice; ignoring the second copy'); }

    return;
  }

  var FRAME_ID = 'milpa-preview-frame';
  var NAME_ID = 'milpa-preview-name';
  var GO_ID = 'milpa-preview-go';

  function frame() { return document.getElementById(FRAME_ID); }
  function name() { return document.getElementById(NAME_ID); }
  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  /** The live wire's route, as the server wrote it on the Preview button. */
  function route() {
    var go = document.getElementById(GO_ID);

    return (go && go.getAttribute('data-live-route')) || '/live';
  }

  /** Point the frame somewhere — an empty source is a no-op, never a blanked frame. */
  function show(src) {
    var target = frame();
    if (target && src) { target.src = src; }

    return src || '';
  }

  /**
   * Ask the wire for the screen BEFORE pointing the frame at it, and say so when it refuses.
   *
   * A name the wire does not serve answers 404 with an empty body, and an iframe pointed at that just
   * goes blank: the human is told nothing and cannot tell an empty screen from a missing one. The probe
   * goes through the shared guard, so a door's 401/403 is the guard's business exactly as everywhere else.
   */
  function open(src, screen) {
    var d = desk();
    if (!src || !d || typeof window.fetch !== 'function') { return show(src); }
    fetch(src, { method: 'GET' }).then(d.guarded).then(function () {
      show(src);
    }).catch(function (err) {
      if (err && err.told) { return; }
      d.notice('error', err && err.status ? tr('preview.failed', screen, err.status) : tr('preview.unreachable', screen));
    });

    return src;
  }

  /** Preview whatever the name box holds, over the live route. */
  function preview() {
    var box = name();
    var typed = box ? String(box.value || '').trim() : '';
    if (typed === '') { return ''; }

    return open(route() + '/page?component=' + encodeURIComponent(typed), typed);
  }

  /** Preview one declared screen: its chip carries the exact path the wire serves it at. */
  function chip(button) {
    var box = name();
    var screen = button.getAttribute('data-screen-name') || '';
    if (box) { box.value = screen; }

    return open(button.getAttribute('data-screen-src') || '', screen);
  }

  live.register('desktopScreens', function () {
    return {
      /** The screen's ONE click handler: the Preview button, or a declared screen's chip. */
      onClick: function (event) {
        var target = event && event.target;
        if (!target || typeof target.closest !== 'function') { return false; }
        if (target.closest('#' + GO_ID)) { preview(); return true; }
        var picked = target.closest('[data-screen-name]');
        if (picked) { chip(picked); return true; }

        return false;
      },
      /** Enter in the name box previews, so the keyboard reaches what the button does. */
      onKey: function (event) {
        if (!event || event.key !== 'Enter') { return false; }
        var target = event.target;
        if (!target || typeof target.closest !== 'function' || !target.closest('#' + NAME_ID)) { return false; }
        preview();

        return true;
      },
    };
  });

  if (live.desktop) { live.desktop.screens = { preview: preview, chip: chip, route: route, show: show, open: open }; }
})();
