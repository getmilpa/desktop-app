/*!
 * desktop-composer — the Desktop's composer bar, as a client module (greenhouse decisions/0211, phase C2).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the composer's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers ONE Alpine factory, `desktopComposer`, which the bar binds with
 * `x-data="desktopComposer()"`, and it owns everything inside that bar:
 *
 *   - the FIELD. It is a milpa/live component (`milpaField`), so setting its text is an INTERACTION with
 *     the component's own data — `reset('')` to clear, `change(text)` to fill a command in — never the
 *     synthetic `input` event that announced a keystroke nobody typed (greenhouse decisions/0211, A3).
 *     The element's value is mirrored in the same tick so a reader that runs before Alpine's next flush
 *     sees the new text.
 *   - `composer.draft` — is there anything to send. The send button BINDS its `disabled` to it together
 *     with `session.working`; nothing pokes the property.
 *   - the draft's token count, live and quiet in the footer (a client estimate at ~4 chars/token: there is
 *     no tokenizer in a browser; the REAL usage is the context figure).
 *   - `send()`, which is the only place that decides what a line IS: a house command (the commands module
 *     runs it), a mistyped command (told), or a prompt (the turn runs it).
 *   - the MODE, whole: the chip's menu, the `composer.mode` / `composer.mode.label` signal pair every turn
 *     reads, and the partial settings post that persists it server-side. `/mode` goes through the same
 *     `applyMode()`, and a save the door REFUSES rolls both signals back — so the chip and the setting can
 *     never disagree, whichever door the bar is being used through.
 *
 * What it does NOT own: the floating panels are the `composer.panel` signal, set by the chips' own
 * bindings and cleared here when the writer types (phase B4); the completion popup is the commands
 * module's, which is handed this field once, at init, instead of reaching for it.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-composer] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopComposer')) {
    if (window.console && console.warn) { console.warn('[desktop-composer] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The permission modes the chip offers — the values a turn carries (greenhouse decisions/0202). */
  var MODES = ['ask', 'acknowledge', 'auto'];
  /** The mode a page falls back to: the one that ASKS. */
  var DEFAULT_MODE = 'ask';
  /** Roughly how many characters a token is worth — an estimate, and named as one. */
  var CHARS_PER_TOKEN = 4;

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }
  function conversation() { var d = desk(); return d ? (d.conversation || null) : null; }
  function commands() { var d = desk(); return d ? (d.commands || null) : null; }
  function turn() { var d = desk(); return d ? (d.turn || null) : null; }

  function signal(key, value) {
    if (typeof live.signal !== 'function') { return null; }

    return arguments.length > 1 ? live.signal(key, value) : live.signal(key);
  }

  /** The bar, as one component. `$root` is the bar itself, so every lookup below is of its own markup. */
  live.register('desktopComposer', function () {
    return {
      /** Whether the mode menu is open — local state, bound by the menu's `:hidden`. */
      menuOpen: false,

      init: function () {
        var self = this;
        var field = this.field();
        if (field) {
          field.addEventListener('input', function () { self.typed(); });
          // Enter sends; Shift+Enter keeps the newline. An open command popup takes its keys first.
          field.addEventListener('keydown', function (event) { self.keydown(event); });
        }
        var c = commands();
        if (c && typeof c.bindField === 'function') { c.bindField(field); }
        // Click-away is ONE signal (greenhouse decisions/0211): the guard owns the document listener and
        // this bar consumes it, instead of hanging a second one for the menu.
        var d = desk();
        if (d && typeof d.onDismiss === 'function') { d.onDismiss(function () { self.menuOpen = false; }); }
        this.refresh();
        if (live.desktop) { live.desktop.composer = self.api(); }
      },

      /** What the rest of the Desktop may ask of the composer — no other module reaches into this bar. */
      api: function () {
        var self = this;

        return {
          field: function () { return self.field(); },
          text: function () { return self.text(); },
          setText: function (text) { return self.setText(text); },
          focus: function () { var el = self.field(); if (el && typeof el.focus === 'function') { el.focus(); } },
          send: function () { return self.send(); },
          mode: function () { return self.mode(); },
          modes: function () { return MODES.slice(); },
          applyMode: function (key) { return self.applyMode(key); },
        };
      },

      /** The composer's text field — this bar's own, never the document's. */
      field: function () {
        var root = this.$root || document;

        return root.querySelector('textarea');
      },

      /** What the writer has typed. */
      text: function () {
        var field = this.field();

        return field ? String(field.value || '') : '';
      },

      /** Whether the turn is running — the send button's glyph and label bind to the same signal. */
      get working() {
        return this.$store.milpa['session.working'] === true;
      },

      /**
       * Set the field's text THROUGH its component: `reset('')` clears value, dirty, touched and error;
       * `change(text)` fills. Measured as the least invasive of the three candidates (decisions/0211, A3).
       */
      setText: function (text) {
        var field = this.field();
        if (!field) { return; }
        var data = this.fieldData(field);
        if (data) {
          if (text === '') { data.reset(''); } else { data.change(text); }
        }
        field.value = text;
        this.refresh();
      },

      /** The field component's own Alpine data, when the framework's runtime is what rendered it. */
      fieldData: function (field) {
        if (!field || !window.Alpine || typeof window.Alpine.$data !== 'function' || typeof field.closest !== 'function') { return null; }
        var root = field.closest('[x-data]');
        if (!root) { return null; }
        try {
          var data = window.Alpine.$data(root);

          return (data && typeof data.reset === 'function' && typeof data.change === 'function') ? data : null;
        } catch (e) { return null; }
      },

      /** The writer typed: the floating panels close, the draft and the completion follow the text. */
      typed: function () {
        this.$store.milpa['composer.panel'] = '';
        this.refresh();
      },

      /** The draft signal, the token count and the completion popup, from whatever the field holds now. */
      refresh: function () {
        var text = this.text();
        signal('composer.draft', text.trim() !== '');
        var count = (this.$root || document).querySelector('#milpa-charcount');
        if (count) {
          var tokens = Math.ceil(text.length / CHARS_PER_TOKEN);
          count.textContent = text.length > 0 ? tr('composer.tokens', tokens) : '';
        }
        var c = commands();
        if (c && typeof c.refresh === 'function') { c.refresh(); }
      },

      /** Enter sends, unless the open completion popup owns the key. */
      keydown: function (event) {
        var c = commands();
        if (c && typeof c.handlesKey === 'function' && c.handlesKey(event)) { return; }
        if (event.key === 'Enter' && !event.shiftKey && !this.working) {
          event.preventDefault();
          this.send();
        }
      },

      /**
       * Send what is written: the user's message lands, the field clears, and the line is either a
       * command the house runs, a mistyped command that is told, or a prompt the turn carries.
       */
      send: function () {
        var text = this.text().trim();
        if (text === '') { return null; }
        var conv = conversation();
        if (conv) { conv.append('user', { text: text }); }
        this.setText('');
        var c = commands();
        if (c) { c.hide(); }
        var field = this.field();
        if (field && typeof field.focus === 'function') { field.focus(); }
        var command = c ? c.parse(text) : null;
        if (command) { return c.run(command); }
        if (c && c.isBareUnknown(text)) { return c.unknown(text); }
        var t = turn();

        return t ? t.run(text) : null;
      },

      /** Stop: the interrupt is signalled; honouring it is the agent runtime's (decisions/0254). */
      stop: function () {
        var t = turn();
        if (t) { t.stop(); }
      },

      /** The mode every turn carries — unset or unknown is the mode that asks. */
      mode: function () {
        var value = signal('composer.mode');

        return MODES.indexOf(value) !== -1 ? value : DEFAULT_MODE;
      },

      /** Whether `key` is the mode in effect — read INSIDE the effect, so aria-current stays reactive. */
      isMode: function (key) {
        return this.$store.milpa['composer.mode'] === key;
      },

      /** The chip's menu. The click is stopped so the guard's click-away does not close what just opened. */
      toggleMenu: function (event) {
        if (event && typeof event.stopPropagation === 'function') { event.stopPropagation(); }
        this.menuOpen = !this.menuOpen;
      },

      /** A choice from the menu. */
      pick: function (key) {
        this.menuOpen = false;
        this.applyMode(key);
      },

      /**
       * The mode changes HERE and only here: both signals, then the partial settings post that persists
       * it. The turn reads the signal; nothing else writes it.
       *
       * OPTIMISTIC, AND ROLLED BACK. The chip answers the click at once — a mode menu that waited on a
       * round trip would feel broken — but a door that REFUSES the save puts both signals back. The mode
       * is not decoration: `composer.mode` is the value every turn carries to the agent (greenhouse
       * decisions/0202), so a chip left reading «Continue automatically» over a server that still says
       * «ask» would be the UI lying about how much the agent may do. That is reachable the moment these
       * surfaces are used through a door that is not the Desktop's own — the admin panel composing this
       * bar while the Desktop stands behind a gate the reader did not pass (decisions/0211, slice 3) —
       * and the guard already says so out loud; this keeps the chip honest with it.
       */
      applyMode: function (key) {
        if (MODES.indexOf(key) === -1) { return null; }
        var d = desk();
        if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
        var was = { mode: this.mode(), label: signal('composer.mode.label') };
        var rollback = function () { signal('composer.mode', was.mode); signal('composer.mode.label', was.label); };
        signal('composer.mode', key);
        signal('composer.mode.label', this.modeLabel(key));

        return fetch('/desktop/settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ mode: key }),
        }).then(d.guarded).catch(function (err) { rollback(); d.failed(err, tr('guard.unreachable')); });
      },

      /** What the chip says for a mode — the label the server rendered on the menu's option. */
      modeLabel: function (key) {
        var option = (this.$root || document).querySelector('.milpa-mode-opt[data-mode="' + key + '"]');

        return option ? (option.getAttribute('data-label') || key) : key;
      },
    };
  });
})();
