/*!
 * desktop-decisions — the cross-session inbox, live (greenhouse decisions/0211, phase D4).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the inbox's renderer ({@see \Milpa\DesktopApp\Live\DecisionsInbox}) through
 * DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 *
 * It registers no Alpine factory: the inbox has no interaction of its own — a decision is answered at the
 * gate of ITS session, and each card is a link there. What it has is one live behaviour (greenhouse
 * decisions/0196): when an agent parks a question while this page is open, a card appears without a
 * reload. The transport says `decision.parked`; this consumes it and CLONES the server-rendered card
 * prototype. The full card (goal, operation, reason) lands on the next load, from the server.
 *
 * It ticks no badge: the decisions count belongs to the sidebar item that shows it, and the sidebar's own
 * module consumes the same fact.
 */
(function () {
  'use strict';

  var live = window.MilpaLive || null;

  /** The graph operation's own HTTP projection — the same door a terminal takes. */
  var DECIDE_ROUTE = '/graph/decide';

  /** The list the cards live in, and the prototype the server rendered for one. */
  var LIST_ID = 'milpa-decisions-list';
  var PROTO = 'milpa-decision-proto';

  function desk() { return (live && live.desktop) || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  if (desk() && desk().decisions) {
    if (window.console && console.warn) { console.warn('[desktop-decisions] loaded twice; ignoring the second copy'); }

    return;
  }

  /**
   * Put one parked question at the top of the inbox, cloned from the prototype.
   *
   * Returns the card, or null when this page renders no inbox (embed mode does not) — a fact nobody can
   * show is a fact ignored, never an error.
   */
  function parked(question) {
    var list = document.getElementById(LIST_ID);
    var proto = document.getElementById(PROTO);
    if (!list || !proto || !('content' in proto)) { return null; }
    var frag = proto.content.cloneNode(true);
    var card = frag.querySelector('.decision-card');
    if (!card) { return null; }
    var asked = card.querySelector('[data-decision-question]');
    if (asked) { asked.textContent = question ? String(question) : tr('decisions.unnamed'); }
    var facts = card.querySelector('[data-decision-facts]');
    if (facts) { facts.textContent = tr('decisions.just_now'); }
    list.insertBefore(card, list.firstChild);

    return card;
  }

  /** Subscribe to the transport's fact, ONCE. */
  var subscribed = false;

  function subscribe() {
    var bus = window.MilpaShell;
    if (subscribed || !bus || typeof bus.on !== 'function') { return false; }
    subscribed = true;
    bus.on('decision.parked', function (fact) { parked((fact && fact.question) || ''); });

    return true;
  }

  if (live && live.desktop) { live.desktop.decisions = { parked: parked, subscribe: subscribe }; }

  subscribe();
  document.addEventListener('DOMContentLoaded', subscribe);

  /**
   * Answering a GRAPH decision, right here.
   *
   * An agent's parked question is answered in the conversation of its own session, so its card is a link.
   * A graph's is answered from the inbox, because the run is parked in a log and not in a process — so the
   * options the server rendered are posted straight to `graph:decide`, the same operation a terminal calls.
   *
   * The options are the cases of the enum the routes were declared with, so this handler never has to know
   * what they mean: it sends the one the human pressed and lets the engine refuse anything it should.
   */
  function answer(card, decision) {
    var d = desk();
    var status = card.querySelector('[data-decision-status]') || card.appendChild(document.createElement('p'));
    status.className = 'decision-card__facts';
    status.setAttribute('data-decision-status', '');
    status.textContent = tr('decisions.answering');

    var body = JSON.stringify({
      graph: card.getAttribute('data-graph') || '',
      instance: card.getAttribute('data-graph-instance') || '',
      decision: decision,
      principal: card.getAttribute('data-graph-principal') || '',
    });

    var send = fetch(DECIDE_ROUTE, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body });

    return (d && d.guardedFlow ? send.then(d.guardedFlow) : send)
      .then(function (response) { return response.json(); })
      .then(function (read) {
        if (read && read.ok === false) { throw new Error(read.error || 'refused'); }
        status.textContent = tr('decisions.answered');
        card.setAttribute('data-answered', '');
      })
      .catch(function (err) {
        status.textContent = tr('decisions.refused', (err && err.message) || 'unknown');
      });
  }

  // Delegated on the list, not bound per card: a decision can arrive live, and a handler bound at load
  // would never see it (greenhouse decisions/0191 — the same lesson the conversation paid for).
  document.addEventListener('click', function (event) {
    var button = event.target && event.target.closest ? event.target.closest('[data-graph-decide]') : null;

    if (!button) { return; }

    var card = button.closest('.decision-card--graph');

    if (!card || card.hasAttribute('data-answered')) { return; }

    event.preventDefault();
    answer(card, button.getAttribute('data-graph-decide') || '');
  });
})();
