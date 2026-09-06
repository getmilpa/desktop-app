/*!
 * desktop-tool-call — a tool call in the thread, as a client module (greenhouse decisions/0211, phase C1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the tool-call prototype's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers NO Alpine factory — the prototype is cloned per call and a
 * per-instance `x-data` double-initialises (greenhouse decisions/0191) — so it registers its KIND in
 * `MilpaLive.desktop.messages.tool` and the conversation routes to it.
 *
 * This message type has behaviour of its own because a raw tool result is not a message: it is machinery,
 * and Milpa Components render machinery LEGIBLY (Rod). So the component decides how its own result reads —
 * a one-line summary (a count for JSON, a truncation otherwise) with the raw pretty-printed underneath —
 * and owns the collapse that keeps the raw out of the way until it is asked for.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live) {
    if (window.console && console.warn) { console.warn('[desktop-tool-call] the milpa/live runtime must load first'); }

    return;
  }

  /** The prototype the conversation clones per call. */
  var PROTO_ID = 'milpa-tool-msg-proto';
  /** How much of a non-JSON result the summary shows before it is cut. */
  var SUMMARY_CHARS = 60;

  function messages() {
    var d = live.desktop || null;
    if (!d) { return null; }
    d.messages = d.messages || {};

    return d.messages;
  }

  var registry = messages();
  if (!registry) {
    if (window.console && console.warn) { console.warn('[desktop-tool-call] the shared desktop runtime must load first'); }

    return;
  }
  if (registry.tool) {
    if (window.console && console.warn) { console.warn('[desktop-tool-call] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The result in one line: what it holds, not what it says. */
  function summary(raw) {
    if (!raw) { return ''; }
    try {
      var parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) { return '→ ' + parsed.length + ' items'; }
      if (parsed && typeof parsed === 'object') {
        return typeof parsed.ok !== 'undefined'
          ? ('→ ok · ' + Object.keys(parsed).length + ' fields')
          : ('→ ' + Object.keys(parsed).length + ' fields');
      }
    } catch (e) { /* not JSON: the truncation below is the summary */ }

    return '→ ' + (raw.length > SUMMARY_CHARS ? raw.slice(0, SUMMARY_CHARS) + '…' : raw);
  }

  /** The raw result, pretty-printed when it is JSON and untouched when it is not. */
  function pretty(raw) {
    try { return JSON.stringify(JSON.parse(raw), null, 2); } catch (e) { return raw; }
  }

  registry.tool = {
    proto: PROTO_ID,
    /** The name, the one-line summary, and the raw body the collapse hides. */
    fill: function (root, opts, at) {
      var name = at(root, '[data-tool-name]');
      if (name) { name.textContent = opts.name || 'tool'; }
      var raw = String(opts.result || '');
      var line = at(root, '[data-tool-summary]');
      if (line) { line.textContent = summary(raw); }
      var body = at(root, '[data-tool-body]');
      if (body) { body.textContent = pretty(raw); }
    },
    /** The component's own readings of a result, published so a test reads the same ones. */
    summary: summary,
    pretty: pretty,
    /** The collapse: CSS state on `data-open`, flipped by the conversation's delegated handler. */
    click: function (event) {
      var toggle = event.target.closest('[data-tool-toggle]');
      if (!toggle) { return false; }
      var call = toggle.closest('.msg--tool');
      if (call) { call.setAttribute('data-open', call.getAttribute('data-open') === '1' ? '0' : '1'); }

      return true;
    },
  };
})();
