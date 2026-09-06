/*!
 * desktop-work-board — moving a card between columns (greenhouse decisions/0211, phase D3).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the board's renderer ({@see \Milpa\DesktopApp\Live\WorkBoard}) through DeclaresClientAssets
 * and emitted, once, by `LiveBoot::html()`. It registers ONE Alpine factory, `desktopWorkBoard`, which the
 * board binds with the five drag events — every one of them DELEGATED on the board's own root, so a card
 * or a column painted later (a re-render over `POST /desktop/live`, a plugin's extension of the board's
 * render events) is draggable without anything re-wiring listeners onto it. The page's inline script used
 * to attach a listener per card and per column at boot, which is exactly why a re-rendered board went
 * dead.
 *
 * Dropping a card PERSISTS its new status through the board's own mutation (`POST /desktop/work`,
 * greenhouse evidence/0484), guarded like every other Desktop call: a door's 401 sends the browser to sign
 * in and come back, a 403 is told once, and nothing here says the move succeeded on its own authority.
 *
 * The drag's look is CSS state, not inline style: the card being carried and the column under it each get
 * a class, and `desktop-work-board.css` says what that looks like.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-work-board] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopWorkBoard')) {
    if (window.console && console.warn) { console.warn('[desktop-work-board] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The board's mutation — the one door a moved card goes through. */
  var ROUTE = '/desktop/work';

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  /** The card currently being carried, across the whole gesture. */
  var carried = null;

  /** The element the event started on, if it is a card. */
  function cardOf(event) {
    var target = event && event.target;

    return (target && typeof target.closest === 'function') ? target.closest('[data-index]') : null;
  }

  /** The column the event is over, if any. */
  function columnOf(event) {
    var target = event && event.target;

    return (target && typeof target.closest === 'function') ? target.closest('.work-col') : null;
  }

  /** Persist the new status; the move is only ever REPORTED as done by the door that did it. */
  function persist(board, card, column) {
    var d = desk();
    if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }

    return fetch(ROUTE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        session: board ? board.getAttribute('data-session') : '',
        index: parseInt(card.getAttribute('data-index'), 10),
        status: column.getAttribute('data-status'),
      }),
    }).then(d.guarded).catch(function (err) { d.failed(err, tr('guard.unreachable')); });
  }

  live.register('desktopWorkBoard', function () {
    return {
      /** Pick a card up. */
      onDragStart: function (event) {
        carried = cardOf(event);
        if (carried) { carried.classList.add('work-card--carried'); }

        return !!carried;
      },
      /** Put it down, wherever the gesture ended. */
      onDragEnd: function () {
        if (carried) { carried.classList.remove('work-card--carried'); }
        carried = null;
      },
      /** Over a column: allow the drop and light the column up. */
      onDragOver: function (event) {
        var column = columnOf(event);
        if (!column) { return false; }
        if (event && typeof event.preventDefault === 'function') { event.preventDefault(); }
        column.classList.add('work-col--over');

        return true;
      },
      /** Off a column again. */
      onDragLeave: function (event) {
        var column = columnOf(event);
        if (column) { column.classList.remove('work-col--over'); }
      },
      /** Drop: move the card in the DOM, then persist its new status through the board's own door. */
      onDrop: function (event) {
        var column = columnOf(event);
        if (event && typeof event.preventDefault === 'function') { event.preventDefault(); }
        if (column) { column.classList.remove('work-col--over'); }
        if (!column || !carried) { return null; }
        var card = carried;
        carried = null;
        card.classList.remove('work-card--carried');
        column.appendChild(card);

        return persist(this.$root, card, column);
      },
    };
  });

  if (live.desktop) { live.desktop.workBoard = { persist: persist }; }
})();
