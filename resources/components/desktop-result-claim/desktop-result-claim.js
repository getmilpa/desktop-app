/*!
 * desktop-result-claim — the closure verdict as a message (greenhouse decisions/0211, phase C1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the result-claim prototype's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers NO Alpine factory — the prototype is cloned per claim and a
 * per-instance `x-data` double-initialises (greenhouse decisions/0191) — so it registers its KIND in
 * `MilpaLive.desktop.messages.result` and the conversation routes to it.
 *
 * This message type has behaviour of its own because the claim is not text: it is a JUDGEMENT with a
 * state. The fill sets `data-verified` (what the CSS paints), the mark, the badge and the tooltip, and it
 * puts the SAME sentence on `aria-label` — the prototype is cloned many times, so a duplicate-id
 * `aria-describedby` would point a screen reader at the first claim of the session for every claim after
 * it. The words themselves come from the conversation, which owns the judgement's copy for both shapes it
 * takes (this standalone line, and the row that rides the agent's answer).
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live) {
    if (window.console && console.warn) { console.warn('[desktop-result-claim] the milpa/live runtime must load first'); }

    return;
  }

  /** The prototype the conversation clones per claim. */
  var PROTO_ID = 'milpa-result-msg-proto';

  function desk() { return live.desktop || null; }
  function conversation() { var d = desk(); return d ? (d.conversation || null) : null; }

  function messages() {
    var d = desk();
    if (!d) { return null; }
    d.messages = d.messages || {};

    return d.messages;
  }

  var registry = messages();
  if (!registry) {
    if (window.console && console.warn) { console.warn('[desktop-result-claim] the shared desktop runtime must load first'); }

    return;
  }
  if (registry.result) {
    if (window.console && console.warn) { console.warn('[desktop-result-claim] loaded twice; ignoring the second copy'); }

    return;
  }

  registry.result = {
    proto: PROTO_ID,
    /** The judgement: its state, its mark, its badge, its tooltip — and the same sentence for a reader. */
    fill: function (root, opts, at) {
      var conv = conversation();
      var verified = opts.verified !== false;
      var text = conv ? conv.tip(verified, opts.reasons) : '';
      root.setAttribute('data-verified', verified ? '1' : '0');
      var mark = at(root, '[data-result-mark]');
      if (mark) { mark.textContent = verified ? '✓' : '⚠'; }
      var badge = at(root, '[data-result-text]');
      if (badge && conv) { badge.textContent = conv.label(verified); }
      var tip = at(root, '[data-result-tip]');
      if (tip) { tip.textContent = text; }
      root.setAttribute('aria-label', conv ? conv.aria(verified, text) : text);
    },
  };
})();
