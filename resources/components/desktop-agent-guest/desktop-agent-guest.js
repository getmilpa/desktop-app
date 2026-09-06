/*!
 * desktop-agent-guest — the contained error of the admin's Agent region (greenhouse decisions/0210).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by {@see \Milpa\DesktopApp\Admin\AgentGuestRenderer} and served at
 * /desktop/assets/c/desktop-agent-guest.js. It is NOT a milpa/live component and registers no factory:
 * the admin is the host here and this package is its guest, so the region cannot reach the host's
 * emitter — the renderer loads this file itself, deferred, next to the region it belongs to.
 *
 * WHY IT EXISTS: the region used to carry the same three lines as `onload=` / `onerror=` attributes, the
 * only executable inline script this package emitted anywhere. The Desktop's own page has carried none
 * since phase D; the surface it contributes to somebody else's page now carries none either.
 *
 * WHAT IT DOES, unchanged: whatever the Desktop ANSWERS — a 403, a 500, the sign-in door — loads inside
 * the frame and stays there, the admin whole around it. Only when the frame gets NO document from the
 * Desktop (the app is down or unreachable: the browser paints its own error page, which is cross-origin,
 * so `contentDocument` is null) is the frame hidden and «The Agent did not answer» shown inside the
 * region. A frame that has not loaded yet has an `about:blank` document, not null, so this cannot fire
 * early; browsers report a failed frame navigation as a `load` of their error page rather than as an
 * `error`, which is why `load` is the hook that matters and `error` is only kept for the engines that
 * fire it.
 */
(function () {
  'use strict';

  var ROOT = '[data-desktop-agent="live"]';

  /** Did the Desktop answer this frame? A same-origin document means yes, whatever its status was. */
  function answered(frame) {
    try { return !!frame.contentDocument; } catch (e) { return true; }
  }

  /** Hide the frame and show the region's notice — inside the region only; nothing outside it moves. */
  function report(root) {
    var frame = root.querySelector('.desktop-agent__frame');
    var notice = root.querySelector('.desktop-agent__notice');
    if (!frame || !notice || answered(frame)) { return false; }
    frame.hidden = true;
    notice.hidden = false;

    return true;
  }

  function watch(root) {
    var frame = root.querySelector('.desktop-agent__frame');
    if (!frame) { return; }
    frame.addEventListener('load', function () { report(root); });
    frame.addEventListener('error', function () { report(root); });
    // The frame may already have finished before this deferred file ran; the window's own load is the
    // last moment at which the answer is certainly in.
    window.addEventListener('load', function () { report(root); });
  }

  function start() {
    var roots = document.querySelectorAll(ROOT);
    for (var i = 0; i < roots.length; i++) { watch(roots[i]); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
