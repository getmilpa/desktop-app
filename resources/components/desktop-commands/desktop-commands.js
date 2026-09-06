/*!
 * desktop-commands — the composer's slash commands, as a client module (greenhouse decisions/0211, phase C4).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * NOT a component: it registers no Alpine factory. A command is not a surface — it is the house's own
 * OPERATION reached from the composer — so like `desktop-guard` and `desktop-turn` it is a RUNTIME module
 * the page declares, hung off `MilpaLive.desktop.commands` and emitted once by `LiveBoot::html()`.
 *
 * The house SERVES the list (`#milpa-commands`, JSON data — never script): its own commands (`/goal`,
 * `/mode`, `/help`) and every user-invocable skill. Each is a governed operation of the house and the
 * Desktop invents no action: it calls the runtime's http projection with the METHOD the list declares —
 * `agent:goal` → `POST /agent/goal`, `skill:invoke` → `GET /skill/invoke` (a read projects as GET). The
 * doctrine's boundary holds: a goal or a mode never pre-consents a signature (requiresConfirmation, the
 * Executable+Privileged ceiling) nor third-party egress — the goal only bounds what the automatic mode
 * may already pre-consent.
 *
 * The completion popup (`#milpa-command-list`, a pure server-rendered `CommandListView`) is this module's
 * behaviour, not its markup: it opens while the field holds only a name being typed, filters the options
 * the server painted, and fills one on click, Enter or Tab. Its visibility is CSS state on `data-open`,
 * and BOTH its click-away and its click-to-fill are the guard's ONE `ui.dismiss` listener — the only
 * listener of its own is the document `mousedown` that keeps the caret in the field, which no click
 * listener can do. The popup is re-resolved per call and nothing is bound to it, so a re-rendered
 * composer bar leaves this module driving the node that is on the page. The field it reads is HANDED to
 * it by the composer at init; this module never reaches into the composer's markup.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live) {
    if (window.console && console.warn) { console.warn('[desktop-commands] the milpa/live runtime must load first'); }

    return;
  }
  if (live.desktop && live.desktop.commands) {
    if (window.console && console.warn) { console.warn('[desktop-commands] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The JSON the house serves the parser, and the popup the same list is rendered as. */
  var LIST_TAG = 'milpa-commands';
  var POPUP_ID = 'milpa-command-list';
  /** The commands the house always has, whatever a data seam serves. */
  var HOUSE = ['goal', 'mode', 'help'];

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }
  function composer() { var d = desk(); return d ? (d.composer || null) : null; }
  function turn() { var d = desk(); return d ? (d.turn || null) : null; }
  function notice(text) { var d = desk(); if (d) { d.notice('system', text); } }

  /** The list the house served — one list, so what completes is exactly what runs. */
  var COMMANDS = (function () {
    var tag = document.getElementById(LIST_TAG);
    try {
      var read = tag ? JSON.parse(tag.textContent || '[]') : [];

      return Array.isArray(read) ? read : [];
    } catch (e) { return []; }
  })();

  function named(name) {
    for (var i = 0; i < COMMANDS.length; i++) {
      if (COMMANDS[i].name === name) { return COMMANDS[i]; }
    }

    return null;
  }

  /**
   * `/name [args]` — the name is `[a-z0-9-]+`, the rest (trimmed) is its argument text.
   *
   * Only a REAL command is intercepted: a house command, or a name the served list carries. Anything else
   * is the model's — "/tmp/app.log has errors" is a prompt, not a command — so this answers null and the
   * turn runs unchanged.
   */
  function parse(text) {
    var match = /^\/([a-z0-9-]+)(?:\s+([\s\S]*))?$/.exec(text);
    if (!match || (HOUSE.indexOf(match[1]) === -1 && !named(match[1]))) { return null; }

    return { name: match[1], args: (match[2] || '').trim() };
  }

  /** Exactly `/name` with no args, naming no command: almost surely a typo, so it is told, not sent. */
  function isBareUnknown(text) { return /^\/[a-z0-9-]+$/.test(text) && !parse(text); }

  /** What a mistyped command is told. */
  function unknown(text) {
    return notice(tr('command.unknown', text, COMMANDS.map(function (c) { return '/' + c.name; }).join(', ')));
  }

  /**
   * Call an operation over its http projection with the method that projection answers to.
   *
   * The op's OWN answer decides: the projector answers 2xx (201 for a mutation) even when the op refused,
   * so `ok` is the transport's ok AND the body's `ok` not being false. Never a silent failure.
   */
  function call(method, path, body) {
    var d = desk();
    if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
    function read(response) {
      return response.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { /* an op may answer with no body at all */ }
        data = (data && typeof data === 'object') ? data : {};

        return { status: response.status, ok: response.ok && data.ok !== false, data: data };
      });
    }
    var request;
    if (method === 'GET') {
      var query = Object.keys(body).map(function (key) {
        return encodeURIComponent(key) + '=' + encodeURIComponent(String(body[key]));
      }).join('&');
      request = fetch(path + (query ? '?' + query : ''), { method: 'GET' });
    } else {
      request = fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    }

    return request.then(d.guarded).then(read).catch(function (err) {
      // The door answered (greenhouse decisions/0209): the status and body survive for failure(); a
      // refusal the guard already told is marked, so it is not told twice.
      if (err && err.status) { return { status: err.status, ok: false, data: err.body || {}, told: !!err.told }; }

      return { status: 0, ok: false, data: { error: String(err) } };
    });
  }

  /** A failed call as one legible line: the op, what happened, and what to do about it. */
  function failure(op, result) {
    var data = result.data || {};
    var error = data.error || (data.errors ? JSON.stringify(data.errors) : '');
    if (result.status >= 200 && result.status < 300) { return tr('op.refused', op, error || tr('op.no_reason')); }
    var hint = (result.status === 404 || result.status === 405) ? tr('op.hint.not_exposed', op)
      : result.status === 428 ? tr('op.hint.confirm', op)
        : result.status === 0 ? tr('op.hint.unreachable')
          : tr('op.hint.failed');
    var line = tr('op.failed', op, result.status, hint);

    return error ? tr('op.detail', line, error) : line;
  }

  /** Run a parsed command: the house's own, or a skill whose body enters the turn as-is. */
  function run(command) {
    if (command.name === 'help') {
      COMMANDS.forEach(function (c) { notice(c.usage + ' — ' + c.description); });

      return null;
    }
    if (command.name === 'goal') {
      // agent:goal — set (`goal`), clear (`clear`), or show (neither) the session's standing goal. The
      // notice reads the RESPONSE (`goal`, `changed`), never the request: what the session holds now.
      var t = turn();
      var body = { session: t ? t.session() : '' };
      if (command.args === 'clear') { body.clear = true; } else if (command.args !== '') { body.goal = command.args; }

      return call('POST', '/agent/goal', body).then(function (result) {
        if (!result.ok) {
          if (!result.told) { notice(failure('agent:goal', result)); }

          return result;
        }
        var goal = typeof result.data.goal === 'string' ? result.data.goal : '';
        if (goal === '') {
          notice(result.data.changed === true ? tr('command.goal.cleared') : tr('command.goal.none'));

          return result;
        }
        notice(tr(result.data.changed === false ? 'command.goal.unchanged' : 'command.goal.set', goal));

        return result;
      });
    }
    if (command.name === 'mode') {
      var bar = composer();
      var key = command.args.toLowerCase();
      var modes = bar ? bar.modes() : [];
      if (modes.indexOf(key) === -1) { notice(tr('command.mode.usage')); return null; }
      // The chip and the saved setting change now; the mode reaches the session with the NEXT turn,
      // which sends it (POST /agent carries `mode`). One writer — no separate agent:mode call.
      bar.applyMode(key);
      notice(tr(key === 'auto' ? 'command.mode.set.auto' : 'command.mode.set', key));

      return null;
    }
    var skill = named(command.name);
    if (!skill || skill.kind !== 'skill') { return null; }

    // skill:invoke — the human's path to a user-invocable skill, a read projected as GET. Its `body` is
    // already the wrapped <skill_content> form: it enters the turn AS-IS, the args after it.
    return call('GET', '/skill/invoke', { name: skill.name }).then(function (result) {
      if (!result.ok || typeof result.data.body !== 'string') {
        if (!result.told) { notice(failure('skill:invoke', result)); }

        return result;
      }
      var t2 = turn();
      if (t2) { t2.run(result.data.body + (command.args !== '' ? '\n\n' + command.args : '')); }

      return result;
    });
  }

  // ── the completion popup ─────────────────────────────────────────────────────────────────────────
  /**
   * The popup is RE-RESOLVED on every call and its clicks are DELEGATED, never bound to the node.
   *
   * A module that caches its element at load and hangs listeners on it is dead the moment its surface is
   * re-rendered — which is the defect `desktop-work-board` documents and this module was still carrying:
   * a plugin extending the bar through `desktop.composer_bar.after_render`, or any live re-render of
   * `desktop-composer`, replaced the popup with an identical fresh node and left this module opening the
   * detached one.
   */
  function popup() { return document.getElementById(POPUP_ID); }
  /** The composer's field, handed over once at its init — never looked up in the document. */
  var field = null;

  function bindField(element) {
    field = element || null;
    if (field && typeof field.setAttribute === 'function') { field.setAttribute('aria-controls', POPUP_ID); }
  }

  function options() {
    var list = popup();

    return list ? Array.prototype.slice.call(list.querySelectorAll('.milpa-cmd')) : [];
  }

  function visible() {
    return options().filter(function (option) { return !option.hidden; });
  }

  function select(option) {
    options().forEach(function (each) { each.setAttribute('aria-selected', each === option ? 'true' : 'false'); });
    if (!field) { return; }
    if (option && option.id) { field.setAttribute('aria-activedescendant', option.id); } else { field.removeAttribute('aria-activedescendant'); }
  }

  function open() { var list = popup(); return !!list && list.getAttribute('data-open') === '1'; }

  function hide() {
    var list = popup();
    if (list) { list.setAttribute('data-open', '0'); }
    if (field) { field.removeAttribute('aria-activedescendant'); }
  }

  /** Open while the field holds only a name being typed (`/go…`), listing what starts with it. */
  function refresh() {
    var list = popup();
    if (!list || !field) { return; }
    var typed = /^\/([a-z0-9-]*)$/.exec(String(field.value || ''));
    if (!typed) { hide(); return; }
    var shown = [];
    options().forEach(function (option) {
      var hit = (option.getAttribute('data-command') || '').indexOf(typed[1]) === 0;
      option.hidden = !hit;
      if (hit) { shown.push(option); }
    });
    list.setAttribute('data-open', shown.length ? '1' : '0');
    select(shown[0] || null);
  }

  /** Fill a name in through the composer's own component, and give the field its focus back. */
  function fill(name) {
    var bar = composer();
    if (!bar) { return; }
    bar.setText('/' + name + ' ');
    hide();
    bar.focus();
  }

  /**
   * Keys the OPEN popup owns: Escape hides, the arrows move, Enter/Tab fill the selected name — unless
   * the typed name already IS that command, in which case Enter sends it. False → the composer's key.
   */
  function handlesKey(event) {
    if (!open()) { return false; }
    var shown = visible();
    var at = -1;
    shown.forEach(function (option, index) { if (option.getAttribute('aria-selected') === 'true') { at = index; } });
    if (event.key === 'Escape') { event.preventDefault(); hide(); return true; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (shown.length) { select(shown[(at + (event.key === 'ArrowDown' ? 1 : shown.length - 1)) % shown.length]); }

      return true;
    }
    if ((event.key === 'Enter' && !event.shiftKey) || (event.key === 'Tab' && !event.shiftKey)) {
      var pick = shown[at] || shown[0];
      var name = pick ? (pick.getAttribute('data-command') || '') : '';
      if (event.key === 'Enter' && field && field.value === '/' + name) { hide(); return false; }
      event.preventDefault();
      if (pick) { fill(name); }

      return true;
    }

    return false;
  }

  /** Whichever option of the CURRENT popup an event landed on, if any. */
  function optionAt(event) {
    var target = event && event.target;
    if (!target || typeof target.closest !== 'function') { return null; }
    var option = target.closest('.milpa-cmd');

    return (option && option.closest('#' + POPUP_ID)) ? option : null;
  }

  // Mousedown inside the popup must not take the caret out of the field. One DOCUMENT listener, so it
  // holds for whatever node is the popup right now — the module binds nothing to an element.
  document.addEventListener('mousedown', function (event) {
    if (optionAt(event)) { event.preventDefault(); }
  });

  var guard = desk();
  if (guard && typeof guard.onDismiss === 'function') {
    // The guard's ONE document click is also this popup's: a click on an option FILLS it, a click in the
    // composer's field is the typist placing the caret (the popup stays), anything else dismisses.
    guard.onDismiss(function (event) {
      var option = optionAt(event);
      if (option) { fill(option.getAttribute('data-command') || ''); return; }
      if (!(field && event && event.target === field)) { hide(); }
    });
  }

  if (live.desktop) {
    live.desktop.commands = {
      list: function () { return COMMANDS.slice(); },
      parse: parse,
      isBareUnknown: isBareUnknown,
      unknown: unknown,
      run: run,
      call: call,
      failure: failure,
      bindField: bindField,
      refresh: refresh,
      hide: hide,
      handlesKey: handlesKey,
    };
  }
})();
