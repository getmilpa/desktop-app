/*!
 * The four screens that stopped being the page's business (greenhouse decisions/0211, phases D2–D4).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * The capabilities two-step, the work board's drag, the declared-screen preview and the live decisions
 * inbox — each now a module its own renderer declares, each running here as the file the browser gets.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, settle, stubFetch } from './support/page.mjs';
import { capabilitiesScreen, decisionsNav, decisionsScreen, screensScreen, workBoard } from './support/shell.mjs';

// ── the capabilities two-step (D2) ─────────────────────────────────────────────────────────────────

/** A page carrying the Capabilities screen, its confirm prototype and the module that drives them. */
function capabilities() {
  const screen = capabilitiesScreen();
  const html = new El('html');
  html.appendChild(screen.root);
  const p = page({ tree: html, elements: { 'milpa-cap-confirm-proto': screen.proto }, modules: ['desktop-capabilities'] });
  // The reload after a successful install is scheduled, not immediate; run the timer at once so the test
  // asserts what the module DID rather than waiting a second for it.
  p.sandbox.setTimeout = (fn) => fn();

  return { p, screen, instance: p.mount('desktopCapabilities', undefined, screen.root) };
}

test('Enable clones the SERVER-rendered confirm box and shows the exact command', () => {
  const { screen, instance } = capabilities();

  instance.onClick({ target: screen.enable });

  const box = screen.card.querySelector('.cap-confirm');
  assert.ok(box, 'the box came from the prototype, not from a string in the module');
  assert.equal(box.querySelector('[data-cap-cmd-text]').textContent, 'composer require milpa/data');
  assert.equal(screen.enable.disabled, true, 'the card cannot be armed twice');

  instance.onClick({ target: screen.enable });
  assert.equal(screen.card.querySelectorAll('.cap-confirm').length, 1, 'one box per card');
});

test('Cancel takes the box down and gives the card its Enable back', () => {
  const { screen, instance } = capabilities();
  instance.onClick({ target: screen.enable });
  const cancel = screen.card.querySelector('[data-cap-cancel]');

  instance.onClick({ target: cancel });

  assert.equal(screen.card.querySelector('.cap-confirm'), null);
  assert.equal(screen.enable.disabled, false);
});

test('Confirm runs the two-step: the 428 carries the token, and the second call sends it back', async () => {
  const { p, screen, instance } = capabilities();
  const calls = stubFetch(p, [response(428, { confirm_token: 'tok-1' }), response(200, { ok: true })]);

  instance.onClick({ target: screen.enable });
  instance.onClick({ target: screen.card.querySelector('[data-cap-go]') });
  await settle();

  assert.equal(calls.length, 2, 'the house asked to confirm, and it was confirmed');
  assert.equal(calls[0].url, '/capabilities/enable');
  assert.equal(calls[0].init.headers['Confirm-Token'], undefined, 'the first step carries no token to carry');
  assert.equal(calls[1].init.headers['Confirm-Token'], 'tok-1');
  assert.equal(JSON.parse(calls[1].init.body).capability, 'milpa/data');

  const box = screen.card.querySelector('.cap-confirm');
  assert.equal(box.querySelector('[data-cap-cmd-text]').textContent, 'Installed milpa/data — reloading…');
  assert.equal(box.querySelector('[data-cap-actions]'), null, 'the buttons are gone once it is decided');
  assert.equal(p.reloads(), 1, 'the page reloads with the new capability installed');
});

test('a refusal is REPORTED in the box, and nothing reloads', async () => {
  const { p, screen, instance } = capabilities();
  stubFetch(p, [response(200, { ok: false, error: 'not on the marketplace' })]);

  instance.onClick({ target: screen.enable });
  instance.onClick({ target: screen.card.querySelector('[data-cap-go]') });
  await settle();

  assert.equal(
    screen.card.querySelector('[data-cap-cmd-text]').textContent,
    'Could not install milpa/data — not on the marketplace',
  );
  assert.equal(p.reloads(), 0, 'nothing succeeded, so nothing is reloaded');
});

test("a door's 403 on the first step is the guard's business, not the screen's", async () => {
  const { p, screen, instance } = capabilities();
  stubFetch(p, [response(403, { error: 'installing is not yours' })]);

  instance.onClick({ target: screen.enable });
  instance.onClick({ target: screen.card.querySelector('[data-cap-go]') });
  await settle();

  assert.equal(p.signal('desktop.notice').text, 'Not allowed here (installing is not yours)', 'the guard told it');
  assert.equal(
    screen.card.querySelector('[data-cap-cmd-text]').textContent,
    'Could not install milpa/data — installing is not yours',
  );
  assert.equal(p.reloads(), 0);
});

// ── the work board's drag (D3) ─────────────────────────────────────────────────────────────────────

test('dropping a card on another column MOVES it and persists its new status', async () => {
  const board = workBoard('s-42');
  const html = new El('html');
  html.appendChild(board.board);
  const p = page({ tree: html, modules: ['desktop-work-board'] });
  const calls = stubFetch(p, [response(200, { ok: true })]);
  const instance = p.mount('desktopWorkBoard', undefined, board.board);

  let prevented = 0;
  instance.onDragStart({ target: board.card });
  assert.equal(board.card.classList.contains('work-card--carried'), true, 'the look is CSS state, not a style');

  instance.onDragOver({ target: board.done, preventDefault: () => { prevented += 1; } });
  assert.equal(board.done.classList.contains('work-col--over'), true);
  assert.equal(prevented, 1, 'without preventDefault the browser refuses the drop');

  instance.onDrop({ target: board.done, preventDefault: () => { prevented += 1; } });
  await settle();

  assert.equal(board.card.parent, board.done, 'the card is in the column it was dropped on');
  assert.equal(board.pending.children.length, 0, 'and only there');
  assert.equal(board.done.classList.contains('work-col--over'), false);
  assert.equal(board.card.classList.contains('work-card--carried'), false);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, '/desktop/work');
  assert.deepEqual(JSON.parse(calls[0].init.body), { session: 's-42', index: 0, status: 'done' });
});

test('a drop with nothing carried persists nothing', async () => {
  const board = workBoard();
  const html = new El('html');
  html.appendChild(board.board);
  const p = page({ tree: html, modules: ['desktop-work-board'] });
  const calls = stubFetch(p, [response(200, {})]);
  const instance = p.mount('desktopWorkBoard', undefined, board.board);

  instance.onDrop({ target: board.done, preventDefault: () => {} });
  await settle();

  assert.equal(calls.length, 0, 'the board asks the door for nothing it was not asked to do');
});

test('a door that refuses the move is TOLD once — the board never says it saved', async () => {
  const board = workBoard();
  const html = new El('html');
  html.appendChild(board.board);
  const p = page({ tree: html, modules: ['desktop-work-board'] });
  stubFetch(p, [response(403, { error: 'the board is read-only' })]);
  const instance = p.mount('desktopWorkBoard', undefined, board.board);

  instance.onDragStart({ target: board.card });
  instance.onDrop({ target: board.done, preventDefault: () => {} });
  await settle();

  assert.equal(p.signal('desktop.notice').text, 'Not allowed here (the board is read-only)');
});

// ── the declared-screen preview (D4) ───────────────────────────────────────────────────────────────

/** A page carrying the Preview screen and its module — and, when asked, the answers the wire gives it. */
function screens(route = '/live', answers = null) {
  const screen = screensScreen(route);
  const html = new El('html');
  html.appendChild(screen.root);
  const p = page({ tree: html, modules: ['desktop-screens'] });
  const told = [];
  p.desktop().onNotice((notice) => told.push(notice.text));
  const calls = answers === null ? [] : stubFetch(p, answers);

  return { p, screen, calls, told, instance: p.mount('desktopScreens', undefined, screen.root) };
}

test('Preview builds the path from the live route the SERVER wrote on the button, and asks the wire first', async () => {
  const { screen, instance, calls } = screens('/wire', [response(200, {})]);
  screen.name.value = '  board  ';

  instance.onClick({ target: screen.root.querySelector('#milpa-preview-go') });
  await settle();

  assert.deepEqual(calls.map((c) => c.url), ['/wire/page?component=board'], 'the wire is asked before the frame is pointed');
  assert.equal(screen.frame.src, '/wire/page?component=board', 'the route is data, not a constant in the module');
});

test('a screen the wire does not serve is REPORTED — never a blank frame with nothing said', async () => {
  // Measured on the cattle: `/live/page?component=…` answers 404 with a ZERO-byte body for a name the
  // wire does not know, and an iframe pointed at that just goes white.
  const { screen, instance, told } = screens('/live', [response(404, {})]);
  screen.frame.src = '/live/page?component=board';
  screen.name.value = 'desktop-statusbar';

  instance.onClick({ target: screen.root.querySelector('#milpa-preview-go') });
  await settle();

  assert.deepEqual(told, ['The wire does not serve «desktop-statusbar» (HTTP 404)']);
  assert.equal(screen.frame.src, '/live/page?component=board', 'the frame keeps what it was showing');
});

test('an empty name previews nothing rather than blanking the frame', () => {
  const { screen, instance } = screens('/live', []);
  screen.frame.src = '/live/page?component=board';
  screen.name.value = '   ';

  instance.onClick({ target: screen.root.querySelector('#milpa-preview-go') });

  assert.equal(screen.frame.src, '/live/page?component=board');
});

test('a chip carries the exact path the wire serves its screen at, and fills the name box', async () => {
  const { screen, instance } = screens('/live', [response(200, {})]);

  instance.onClick({ target: screen.chip });
  await settle();

  assert.equal(screen.name.value, 'board');
  assert.equal(screen.frame.src, '/live/page?component=board');
});

test('Enter in the name box does what the button does; a key elsewhere does nothing', () => {
  const { screen, instance } = screens();
  screen.name.value = 'inbox';

  assert.equal(instance.onKey({ key: 'a', target: screen.name }), false);
  assert.equal(instance.onKey({ key: 'Enter', target: screen.chip }), false, 'Enter outside the box is not a preview');
  assert.equal(instance.onKey({ key: 'Enter', target: screen.name }), true);
  assert.equal(screen.frame.src, '/live/page?component=inbox');
});

// ── the live decisions inbox (D4) ──────────────────────────────────────────────────────────────────

test('a question parked while the page is open lands as a card cloned from the prototype', () => {
  const inbox = decisionsScreen();
  const html = new El('html');
  html.appendChild(inbox.root);
  const p = page({ tree: html, elements: { 'milpa-decision-proto': inbox.proto }, bus: true, modules: ['desktop-decisions'] });

  p.bus().emit('decision.parked', { question: 'May I write to config/app.php?' });

  assert.equal(inbox.list.children.length, 1);
  const card = inbox.list.children[0];
  assert.equal(card.querySelector('[data-decision-question]').textContent, 'May I write to config/app.php?');
  assert.equal(card.querySelector('[data-decision-facts]').textContent, 'just now · open the conversation to answer');

  p.bus().emit('decision.parked', { question: 'And to composer.json?' });
  assert.equal(inbox.list.children.length, 2);
  assert.equal(inbox.list.children[0].querySelector('[data-decision-question]').textContent, 'And to composer.json?', 'newest first');
});

test('a question with no text still lands, named by the catalog', () => {
  const inbox = decisionsScreen();
  const html = new El('html');
  html.appendChild(inbox.root);
  const p = page({ tree: html, elements: { 'milpa-decision-proto': inbox.proto }, bus: true, modules: ['desktop-decisions'] });

  p.bus().emit('decision.parked', {});

  assert.equal(inbox.list.children[0].querySelector('[data-decision-question]').textContent, 'A question is waiting for you.');
});

test('a page that renders no inbox (embed mode) ignores the fact instead of throwing', () => {
  const p = page({ tree: new El('html'), bus: true, modules: ['desktop-decisions'] });

  p.bus().emit('decision.parked', { question: 'anything' });

  assert.deepEqual(p.warnings, [], 'no module complained, and nothing threw');
  assert.equal(p.desktop().decisions.parked('anything'), null);
});

test("the badge is the SIDEBAR's, and the sidebar's own module ticks it", () => {
  const html = new El('html');
  const nav = html.appendChild(decisionsNav({ badge: 2 }));
  const p = page({ tree: html, bus: true, modules: ['desktop-sidebar'] });

  p.bus().emit('decision.parked', { question: 'x' });

  const badge = nav.querySelector('.mui-sidebar__item-badge');
  assert.equal(badge.textContent, '3');
  assert.equal(badge.hidden, false);
});

test('a sidebar that never rendered a badge grows one on the first parked question', () => {
  const html = new El('html');
  const nav = html.appendChild(decisionsNav());
  const p = page({ tree: html, bus: true, modules: ['desktop-sidebar'] });

  p.bus().emit('decision.parked', { question: 'x' });

  const badge = nav.querySelector('.mui-sidebar__item-badge');
  assert.ok(badge, 'the row grew its own badge');
  assert.equal(badge.textContent, '1');
  assert.equal(badge.classList.contains('mui-badge--warning'), true);
});
