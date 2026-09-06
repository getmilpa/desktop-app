/*!
 * desktop-settings — the Desktop's Settings screen, as a client module (greenhouse decisions/0211, phase B7).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the screen's renderer through DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 * It registers ONE Alpine factory, `desktopSettings`, bound by the screen with
 * `x-data="desktopSettings()"`.
 *
 *   - `save()` posts the form to `POST /desktop/settings` THROUGH THE GUARD and reports what the door
 *     answered: green «Saved» ONLY on a 2xx, a warning naming the status on anything else, and a 401
 *     leaves for sign-in and comes back rather than painting a badge over a page that is going away
 *     (greenhouse decisions/0209). The report is the shared `settings.saved` signal, so the badge is a
 *     BINDING and nothing pokes its text, its class or its hidden.
 *   - `discard()` reloads, which is what "discard" means when the server holds the values.
 *   - `setTheme()` / `isTheme()` are the SHARED `ui.theme` signal the topbar's module owns — these three
 *     buttons and the window chrome's toggle set one value, so they can never disagree.
 *
 * The fields are read from the component's own root, never from the document: this screen owns them.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-settings] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopSettings')) {
    if (window.console && console.warn) { console.warn('[desktop-settings] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The shared signal the save badge is: `{ok, text}` while a save is being reported, else null. */
  var SAVED_SIGNAL = 'settings.saved';
  /** The shared signal the theme is — the topbar's module owns the rule, this screen only sets it. */
  var THEME_SIGNAL = 'ui.theme';
  /** How long a report stands: long enough to read, short enough not to linger. */
  var HOLD_OK_MS = 2000;
  var HOLD_FAILED_MS = 4000;

  function desk() { return live.desktop || null; }
  function tr(key, arg) { var d = desk(); return d ? d.tr(key, arg) : key; }

  live.register('desktopSettings', function () {
    return {
      /** The save report, or null when there is nothing to report. */
      get saved() {
        return this.$store.milpa[SAVED_SIGNAL] || null;
      },
      /** Whether the report is a success — the badge's colour is a binding on it. */
      get savedOk() {
        var report = this.saved;

        return !report || report.ok !== false;
      },
      /** What the badge says: the door's own answer, in the declared locale. */
      get savedText() {
        var report = this.saved;

        return report ? String(report.text || '') : '';
      },
      /** Report a save (or its failure) and clear the report after it has been read. */
      report: function (ok, text) {
        var self = this;
        this.$store.milpa[SAVED_SIGNAL] = { ok: !!ok, text: String(text || '') };
        setTimeout(function () { self.$store.milpa[SAVED_SIGNAL] = null; }, ok ? HOLD_OK_MS : HOLD_FAILED_MS);
      },
      /** The form as the writer takes it — read from this component's own root. */
      values: function () {
        var root = this.$root || document;
        var endpoint = root.querySelector('#set-end');
        var stream = root.querySelector('#set-stream');
        var compact = root.querySelector('#set-comp');
        var mode = root.querySelector('input[name="set-mode"]:checked');

        return {
          endpoint: endpoint ? endpoint.value : '',
          stream: stream ? stream.checked : true,
          compact: compact ? compact.checked : true,
          mode: mode ? mode.value : 'ask',
        };
      },
      /** Persist. «Saved» is the DOOR's answer, never this screen's assumption. */
      save: function () {
        var d = desk();
        if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
        var self = this;

        return fetch('/desktop/settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(this.values()),
        }).then(d.guarded).then(function () {
          self.report(true, tr('settings.saved'));
        }).catch(function (err) {
          self.report(false, tr('settings.save_failed', (err && err.status) || 0));
        });
      },
      /** Discard: the persisted values are the server's, so reloading IS the discard. */
      discard: function () {
        location.reload();
      },
      /** Choose the shell's theme — the same value the window chrome's toggle sets. */
      setTheme: function (choice) {
        var d = desk();
        if (d && d.theme && typeof d.theme.set === 'function') { d.theme.set(choice); }
      },
      /** Whether `choice` is the theme in effect — read INSIDE the effect, so aria-pressed stays reactive. */
      isTheme: function (choice) {
        return this.$store.milpa[THEME_SIGNAL] === choice;
      },
    };
  });
})();
