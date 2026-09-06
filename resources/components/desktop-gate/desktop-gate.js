/*!
 * desktop-gate — the Desktop's consent gate, as a client module (greenhouse decisions/0211, phase B3).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the gate's renderer through DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 * It registers ONE Alpine factory, `desktopGate`, which the server-rendered card binds with
 * `x-data="desktopGate()"`.
 *
 * The live `gate.opened` fact (the hub, through `MilpaShell`) FILLS the component's own data — operation,
 * arguments, the sentence and the same-origin passkey link — and opens the shared `desktop.gate.open`
 * signal; the card's fields are BINDINGS on that data. Nothing is written into the DOM by hand.
 *
 * The bug this fixes by execution (greenhouse decisions/0211, B3): the page's handler ended by unhiding
 * an element with the id `milpa-decisions-badge` — which NOTHING in this package renders. So every
 * `gate.opened` threw a TypeError right after filling the card, and the Dismiss listener threw on every
 * click. The decisions count is the SIDEBAR's badge and `MilpaShell.addDecision()` is what ticks it; the
 * gate does not own it, so the gate no longer reaches for it.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-gate] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopGate')) {
    if (window.console && console.warn) { console.warn('[desktop-gate] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The shared signal the card's visibility is (the server seeds it false). */
  var OPEN_SIGNAL = 'desktop.gate.open';
  /** The app's own same-origin ceremony — the Desktop never signs, it links to the door that does. */
  var INTENT = '/webauthn/intent';

  /** The approval link for a parked call: the operation, its arguments and the session, all encoded. */
  function intentHref(gate) {
    var args = (gate && gate.arguments) || {};

    return INTENT + '?operation=' + encodeURIComponent((gate && gate.operation) || '')
      + '&arguments=' + encodeURIComponent(JSON.stringify(args))
      + '&session=' + encodeURIComponent((gate && gate.session) || '');
  }

  live.register('desktopGate', function () {
    return {
      /** The parked call, as the card shows it. Empty until an agent parks one. */
      operation: '',
      args: '',
      action: 'An agent is asking to act.',
      href: '#',
      /** The card's visibility IS the shared signal — one truth, so any surface can open or close it. */
      get open() {
        return this.$store.milpa[OPEN_SIGNAL] === true;
      },
      init: function () {
        var self = this;
        if (window.MilpaShell && typeof window.MilpaShell.on === 'function') {
          window.MilpaShell.on('gate.opened', function (gate) { self.parked(gate); });
        }
      },
      /** A question was parked: fill the card from the fact and open it, on the Conversation tab. */
      parked: function (gate) {
        var operation = (gate && gate.operation) || '';
        this.operation = operation;
        this.args = JSON.stringify((gate && gate.arguments) || {});
        this.action = 'An agent is asking to run ' + operation + '.';
        this.href = intentHref(gate);
        this.$store.milpa[OPEN_SIGNAL] = true;
        // The question is in the conversation: show it there, whatever tab was open.
        if (live.desktop && typeof live.desktop.showTab === 'function') { live.desktop.showTab('chat'); }
      },
      /** Dismiss keeps the question parked on the server; it only closes the card here. */
      dismiss: function () {
        this.$store.milpa[OPEN_SIGNAL] = false;
      },
    };
  });
})();
