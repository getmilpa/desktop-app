/*!
 * desktop-tabs — the Desktop shell's main tablist, as a client module (greenhouse decisions/0211, phase B1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the tablist's renderer through DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 * It registers ONE Alpine factory, `desktopTabs`, which the server-rendered strip binds with
 * `x-data="desktopTabs({ signal, active })"`:
 *
 *   - `select(key)` sets the shared tab signal — the ONE truth every pane and the composer dock read;
 *   - `isActive(key)` is what `aria-selected` binds to, so the highlight tracks the signal rather than
 *     being poked by a click handler.
 *
 * The component's public contract is the SIGNAL, not this object: anything that must switch a tab sets
 * `desktop.tab` (the gate does, when a question is parked). `MilpaLive.desktop.showTab(key)` is that
 * one line, published here so the other Desktop modules never re-derive the key.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-tabs] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopTabs')) {
    if (window.console && console.warn) { console.warn('[desktop-tabs] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The signal a strip drives when its markup names none — the shell's own tab signal. */
  var DEFAULT_SIGNAL = 'desktop.tab';

  live.register('desktopTabs', function (config) {
    var options = config || {};

    return {
      /** The shared signal this strip reads and writes; the server names it in the markup. */
      signal: typeof options.signal === 'string' && options.signal !== '' ? options.signal : DEFAULT_SIGNAL,
      /** The tab the server painted as current — the value before the store answers. */
      fallback: typeof options.active === 'string' ? options.active : '',
      /** Switch: one signal set. The panes, the composer dock and this strip's own highlight follow it. */
      select: function (key) {
        this.$store.milpa[this.signal] = key;
      },
      /** Whether `key` is the current tab — read INSIDE the effect, so the binding is reactive. */
      isActive: function (key) {
        var current = this.$store.milpa[this.signal];

        return (current === undefined || current === null ? this.fallback : current) === key;
      },
    };
  });

  // The strip's contract for the rest of the Desktop: switching a tab is setting the signal.
  if (live.desktop) {
    live.desktop.showTab = function (key) {
      if (typeof live.signal === 'function') { live.signal(DEFAULT_SIGNAL, key); }
    };
  }
})();
