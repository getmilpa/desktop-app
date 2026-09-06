/*!
 * desktop-turn — the governed turn, as a client module (greenhouse decisions/0211, phase C3).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * NOT a component: it registers no Alpine factory, because a turn is not a surface — nothing renders it.
 * Like `desktop-guard` it is a RUNTIME module the page declares, hung off `MilpaLive.desktop.turn`, and
 * emitted once by `LiveBoot::html()` before the component modules that call it.
 *
 * It is the ONE place the Desktop starts a governed turn (greenhouse decisions/0190): `POST /agent` with
 * the prompt, the session the server minted and the mode the composer's chip holds. The Desktop does not
 * run the turn — it asks for one — so what comes back is only ever REPORTED:
 *
 *   - the answer, the pause or the error go to the conversation as messages, through the thread's own
 *     API. The turn touches no element of the conversation and knows no prototype;
 *   - the closure verdict rides the last answer, or lands as a standalone claim when there is none;
 *   - the counters are SIGNALS (`session.turns`, `session.steps`, `session.tokens`, `context.used`), so
 *     the composer chips, the status bar and the panels are one truth projected, not three copies. The
 *     token figures are the provider's REAL numbers (greenhouse decisions/0192) — absent when the
 *     provider never said, so the seed stands rather than a fabricated zero;
 *   - `session.working` is the turn's own state signal: the send button's glyph, its label and the
 *     topbar badge BIND to it. It is set here and when the hub says the session's state changed.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live) {
    if (window.console && console.warn) { console.warn('[desktop-turn] the milpa/live runtime must load first'); }

    return;
  }
  if (live.desktop && live.desktop.turn) {
    if (window.console && console.warn) { console.warn('[desktop-turn] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The governed turn's HTTP surface — the `agent` operation's own projection. */
  var ROUTE = '/agent';
  /** Where the server tells the page which agent session this Desktop drives. */
  var SESSION_TAG = 'milpa-desktop-session';

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }
  function conversation() { var d = desk(); return d ? (d.conversation || null) : null; }
  function composer() { var d = desk(); return d ? (d.composer || null) : null; }

  function signal(key, value) {
    if (typeof live.signal !== 'function') { return null; }

    return arguments.length > 1 ? live.signal(key, value) : live.signal(key);
  }

  /**
   * The agent session this Desktop drives: the server minted it, set it in a cookie and scoped the hub's
   * JWT to its exact stream topic, then wrote it here as data — never as executable script.
   */
  var SESSION = (function () {
    var tag = document.getElementById(SESSION_TAG);
    try {
      var read = tag ? JSON.parse(tag.textContent || '{}') : {};

      return (read && typeof read.agent === 'string') ? read.agent : '';
    } catch (e) { return ''; }
  })();

  /** The last prompt sent, so the answer's Regenerate tool can ask for the same turn again. */
  var last = '';

  /** A counter signal as a number. */
  function counter(key) {
    var value = signal(key);

    return parseInt(value, 10) || 0;
  }

  /** A token figure as the UI says it. */
  function kfmt(n) { return (n / 1000).toFixed(2) + 'K'; }

  /**
   * What the turn reported, projected onto the shared counters.
   *
   * A PARKED turn moves the figures it really spent — its steps and the provider's real tokens — but not
   * `session.turns`: it has not closed, and counting it as a completed turn is the counter lying.
   */
  function counters(result) {
    if (!(result && result.paused)) { signal('session.turns', counter('session.turns') + 1); }
    if (typeof result.steps === 'number') { signal('session.steps', counter('session.steps') + result.steps); }
    if (typeof result.tokens === 'number') { signal('session.tokens', kfmt(result.tokens)); }
    if (typeof result.contextTokens === 'number') { signal('context.used', kfmt(result.contextTokens)); }
  }

  /** The turn's state, as the signal every surface that follows it binds to — in the declared locale. */
  function working(on) {
    signal('session.working', !!on);
    signal('session.state.label', tr(on ? 'session.state.working' : 'session.state.idle'));
  }

  /**
   * Report what came back — as MESSAGES, through the thread's own API.
   *
   * A PARKED turn is tested FIRST. The house answers a parked turn `ok:true` WITH `paused:true` and, in
   * ask mode, the parked question as its `answer` — so an `ok && answer` branch that ran first rendered
   * the question as an ordinary agent message and the session looked finished while it was waiting. The
   * answer is still said (it is what the agent asked), and the pause is said after it, always.
   */
  function report(result) {
    var conv = conversation();
    if (!conv) { return; }
    if (result && result.paused) {
      if (result.answer) { conv.append('agent', { text: result.answer }); }
      conv.append('system', { text: result.hint || tr('turn.paused') });
    } else if (result && result.ok && result.answer) {
      conv.append('agent', { text: result.answer });
    } else if (result && result.error) {
      conv.append('system', { text: result.error });
    }
    // The closure verdict (greenhouse decisions/0191, evidence/0442): the ledger either backs the answer
    // or disputes it. Only shown when the house actually judged the turn.
    if (result && result.closure) {
      var verified = result.closure.verified !== false;
      var why = (result.closure.reasons || []).join('; ');
      if (!conv.verdict(verified, why)) { conv.append('result', { verified: verified, reasons: why }); }
    }
  }

  /** Start a governed turn. The mode is the chip's VALUE, asked of the composer, never assumed. */
  function run(text) {
    var d = desk();
    if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
    last = text;
    var bar = composer();
    // The turn's own state is set HERE, at the start of the turn the Desktop asked for. The hub's
    // `session.state` fact was the ONLY writer before, so on a Desktop with no hub wired a running turn
    // was invisible: the send button never became a stop and the topbar kept reading «Ready». The hub
    // still corrects this the moment the backend has something of its own to say.
    working(true);

    return fetch(ROUTE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ prompt: text, session: SESSION, mode: bar ? bar.mode() : 'ask' }),
    }).then(d.guarded).then(function (response) {
      return response.json();
    }).then(function (result) {
      // The turn came back — answered, parked or refused: either way this page is not running it any
      // more. A 401 that sends the browser to the door never settles, so nothing is repainted there.
      working(false);
      report(result);
      if (result && result.ok) { counters(result); }

      return result;
    }).catch(function (err) { working(false); d.failed(err, tr('guard.unreachable')); });
  }

  /**
   * The hub's own account of the session's state (greenhouse decisions/0190): "working" is the backend's
   * to declare, so the Desktop reflects it. Subscribed at LOAD — the stream is connected on
   * `DOMContentLoaded`, after every deferred module has run, so a fact queued at the hub is never
   * delivered to nobody.
   */
  var subscribed = false;

  function subscribe() {
    var shell = window.MilpaShell;
    if (subscribed || !shell || typeof shell.on !== 'function') { return false; }
    subscribed = true;
    shell.on('session.state', function (fact) {
      var conv = conversation();
      if (conv && !(fact && fact.state === 'working')) { conv.endReasoning(); }
      working(!!(fact && fact.state === 'working'));
    });

    return true;
  }

  if (live.desktop) {
    live.desktop.turn = {
      run: run,
      /** Re-run the last prompt — the answer's Regenerate tool. */
      regenerate: function () { return last === '' ? null : run(last); },
      /** Stop: the interrupt is signalled and said; honouring it is the agent runtime's. */
      stop: function () {
        working(false);
        var d = desk();
        if (d) { d.notice('system', tr('turn.stop_requested')); }
      },
      working: working,
      counters: counters,
      session: function () { return SESSION; },
      subscribe: subscribe,
    };
  }

  subscribe();
})();
