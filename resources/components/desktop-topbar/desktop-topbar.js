/*!
 * desktop-topbar — the Desktop shell's header, as a client module (greenhouse decisions/0211, phase B2).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the topbar's renderer through DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 *
 * What it owns:
 *   - the `desktopTopbar` Alpine factory the header binds with `x-data="desktopTopbar()"`: `working` is
 *     the `session.working` signal read through the component, so the live badge's modifiers are a
 *     BINDING and nothing pokes its className;
 *   - the shell's THEME. The choice is one value in three places — the `<html>` element's `data-theme`,
 *     the window chrome's toggle and the Settings screen's three buttons — so it is a SIGNAL, `ui.theme`
 *     (`system | dark | light`), and every surface binds to it. It is restored from `localStorage` at
 *     MODULE LOAD (before Alpine walks the page, as early as a deferred script can act) and published as
 *     `MilpaLive.desktop.theme` so the Settings module sets it without carrying a second copy of the rule.
 *
 * `localStorage` is wrapped in try/catch throughout: a private window that throws must not break the shell.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-topbar] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopTopbar')) {
    if (window.console && console.warn) { console.warn('[desktop-topbar] loaded twice; ignoring the second copy'); }

    return;
  }

  /** Where the choice is remembered, and the shared signal every surface binds to. */
  var STORAGE_KEY = 'milpa.theme';
  var THEME_SIGNAL = 'ui.theme';

  function signal(key, value) {
    if (typeof live.signal !== 'function') { return null; }

    return arguments.length > 1 ? live.signal(key, value) : live.signal(key);
  }

  /** The remembered choice, or 'system' — an unreadable store is an unremembered choice, never an error. */
  function remembered() {
    var value = null;
    try { value = window.localStorage.getItem(STORAGE_KEY); } catch (e) { /* a private window throws */ }

    return (value === 'dark' || value === 'light') ? value : 'system';
  }

  function remember(choice) {
    try {
      if (choice === 'system') { window.localStorage.removeItem(STORAGE_KEY); } else { window.localStorage.setItem(STORAGE_KEY, choice); }
    } catch (e) { /* a private window throws */ }
  }

  /** Apply the choice to the document: 'system' drops the attribute so prefers-color-scheme decides. */
  function apply(choice) {
    var root = document.documentElement;
    if (choice === 'system') { root.removeAttribute('data-theme'); } else { root.setAttribute('data-theme', choice); }
    signal(THEME_SIGNAL, choice);

    return choice;
  }

  var theme = {
    /** The choice in effect — the signal, which the restore below always seeds. */
    current: function () {
      var value = signal(THEME_SIGNAL);

      return (value === 'dark' || value === 'light' || value === 'system') ? value : 'system';
    },
    /** Choose: applied to the document, remembered, and announced to every surface bound to the signal. */
    set: function (choice) {
      var value = (choice === 'dark' || choice === 'light') ? choice : 'system';
      remember(value);

      return apply(value);
    },
    /** The chrome toggle's verb: dark ⇄ light, whatever the document is showing right now. */
    toggle: function () {
      return theme.set(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    },
  };

  // Restore before Alpine walks the page: the remembered choice is applied as early as a declared module
  // can act, so the shell does not paint the default and then correct itself.
  apply(remembered());

  // The window chrome's toggle lives outside this component's root — it is the only control of the shell's
  // chrome that belongs to the topbar's surface, so the topbar's module wires it rather than the page.
  var toggle = document.getElementById('milpa-theme');
  if (toggle) { toggle.addEventListener('click', function () { theme.toggle(); }); }

  live.register('desktopTopbar', function () {
    return {
      /** Whether the turn is running — read INSIDE the effect, so the badge's modifiers stay reactive. */
      get working() {
        return this.$store.milpa['session.working'] === true;
      },
      /** The theme in effect, as a binding for any header control that shows it. */
      get theme() {
        return this.$store.milpa[THEME_SIGNAL];
      },
      /** Switch the shell between dark and light — the same verb the chrome toggle runs. */
      toggleTheme: function () {
        theme.toggle();
      },
    };
  });

  if (live.desktop) { live.desktop.theme = theme; }
})();
