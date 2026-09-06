/**
 * The composer, the governed turn and the slash commands, measured by EXECUTION (greenhouse
 * decisions/0211, phases C2–C4).
 *
 * The three modules that make a line typed into the Desktop become something: the composer decides WHAT a
 * line is, the commands run the house's own operations, and the turn is the one place `POST /agent` is
 * called. They are loaded here exactly as `LiveBoot` emits them — the guard, the turn and the commands
 * first, the component modules after — over the markup the server renders.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { response, settle, stubFetch } from './support/page.mjs';
import { HOUSE_COMMANDS, commandPopup, shellPage } from './support/shell.mjs';

/** The whole conversation half of the shell, with the composer mounted as Alpine would mount it. */
function composerPage({ doors = null } = {}) {
  const { p, chat, bar } = shellPage({
    doors,
    modules: [
      'desktop-turn', 'desktop-commands',
      'desktop-conversation', 'desktop-composer',
      'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim',
    ],
  });
  const composer = p.mount('desktopComposer', undefined, bar.wrap);
  const told = [];
  p.desktop().onNotice((notice) => told.push(notice.text));

  return { p, chat, bar, composer, told };
}

/** Type into the composer's field the way a writer does — the module listens for `input`. */
function type(bar, text) {
  bar.field.value = text;
  bar.field.fire('input');
}

// ── desktop-composer (C2) ───────────────────────────────────────────────────────────────────────────
test('the draft signal and the token count follow what is typed, and the panels close', () => {
  const { p, bar, composer } = composerPage();
  p.signal('composer.panel', 'context');

  type(bar, 'ship the slice');

  assert.equal(p.signal('composer.draft'), true, 'the send button BINDS its disabled to this');
  assert.equal(p.signal('composer.panel'), '', 'typing closes whichever panel was open');
  assert.equal(bar.wrap.querySelector('#milpa-charcount').textContent, '~4 tokens');

  type(bar, '   ');
  assert.equal(p.signal('composer.draft'), false, 'whitespace is not a draft');
  assert.equal(bar.wrap.querySelector('#milpa-charcount').textContent, '~1 tokens', 'but it is text, and it costs');

  type(bar, '');
  assert.equal(bar.wrap.querySelector('#milpa-charcount').textContent, '', 'empty says nothing');
  assert.equal(composer.working, false);
});

test('send routes a COMMAND to the house and a prompt to the turn — and clears the field either way', async () => {
  const { p, bar, chat, composer } = composerPage();
  const calls = stubFetch(p, [response(200, { ok: true, goal: 'ship it', changed: true }), response(200, { ok: true, answer: 'on it' })]);

  type(bar, '/goal ship it');
  composer.send();
  await settle();

  assert.equal(calls[0].url, '/agent/goal', 'a real command is the house\'s operation, not the model\'s');
  assert.deepEqual(JSON.parse(calls[0].init.body), { session: 'desk-0123456789abcdef', goal: 'ship it' });
  assert.equal(bar.field.value, '', 'the field is cleared through its own component');
  assert.equal(chat.children[0].querySelector('[data-user-body]').textContent, '/goal ship it', 'what was sent is in the thread');

  type(bar, 'and now write it up');
  composer.send();
  await settle();

  assert.equal(calls[1].url, '/agent', 'anything that is not a command is a prompt');
  assert.deepEqual(JSON.parse(calls[1].init.body), { prompt: 'and now write it up', session: 'desk-0123456789abcdef', mode: 'ask' });
});

test('a bare unknown command is TOLD, never sent to the model', async () => {
  const { p, bar, composer, told } = composerPage();
  const calls = stubFetch(p, []);

  type(bar, '/nope');
  composer.send();
  await settle();

  assert.deepEqual(calls, [], 'nothing left the browser');
  assert.deepEqual(told, ['unknown command /nope — commands: /goal, /mode, /help']);

  // With arguments it is a path, not a typo — the model gets it.
  stubFetch(p, [response(200, { ok: true })]);
  type(bar, '/tmp/app.log has errors');
  composer.send();
  await settle();
  assert.equal(p.desktop().commands.parse('/tmp/app.log has errors'), null);
});

test('the mode chip writes BOTH signals and persists through the door — and /mode runs the same one', async () => {
  const { p, bar, composer, told } = composerPage();
  const calls = stubFetch(p, [response(200, { ok: true }), response(200, { ok: true })]);

  composer.toggleMenu({ stopPropagation() {} });
  assert.equal(composer.menuOpen, true);

  composer.pick('auto');
  await settle();

  assert.equal(composer.menuOpen, false, 'choosing closes the menu');
  assert.equal(p.signal('composer.mode'), 'auto', 'the VALUE every turn sends');
  assert.equal(p.signal('composer.mode.label'), 'Continue automatically', 'and the label the chip shows');
  assert.equal(composer.isMode('auto'), true);
  assert.equal(calls[0].url, '/desktop/settings');
  assert.deepEqual(JSON.parse(calls[0].init.body), { mode: 'auto' }, 'a partial post that merges');

  p.desktop().commands.run({ name: 'mode', args: 'ASK' });
  await settle();

  assert.equal(p.signal('composer.mode'), 'ask', '/mode is the same writer');
  assert.deepEqual(told, ['mode ask — applies from the next turn']);

  p.desktop().commands.run({ name: 'mode', args: 'reckless' });
  assert.deepEqual(told.slice(-1), ['usage: /mode ask|acknowledge|auto'], 'a mode nobody declared is refused');
  assert.equal(p.signal('composer.mode'), 'ask');

  // The click-away the guard owns closes the menu; the composer hangs no listener of its own.
  composer.toggleMenu({ stopPropagation() {} });
  p.listeners.click.forEach((fn) => fn({ target: bar.wrap }));
  assert.equal(composer.menuOpen, false);
});

/**
 * A DOOR THAT REFUSES ROLLS THE CHIP BACK. `composer.mode` is not decoration: it is the value every turn
 * carries to the agent (greenhouse decisions/0202), so a chip reading «Continue automatically» over a
 * server that still says «ask» is the UI lying about how much the agent may do. Reachable the moment
 * these surfaces are used through a door that is not the Desktop's own — the admin panel composing this
 * bar while the Desktop stands behind a gate the reader did not pass (decisions/0211, slice 3).
 */
test('a mode the door refuses is rolled back — the chip never disagrees with the setting', async () => {
  const { p, composer, told } = composerPage();
  p.signal('composer.mode', 'ask');
  p.signal('composer.mode.label', 'Ask before changing');

  // The positive control first: a door that ACCEPTS leaves the new mode standing.
  stubFetch(p, [response(200, { ok: true })]);
  composer.pick('auto');
  await settle();
  assert.equal(p.signal('composer.mode'), 'auto');
  assert.equal(p.signal('composer.mode.label'), 'Continue automatically');

  // …and a door that refuses — the Desktop gated loopback-only, read from somewhere else — puts it back.
  const calls = stubFetch(p, [response(403, { ok: false, error: 'loopback_only' })]);
  composer.pick('ask');
  await settle();

  assert.equal(calls[0].url, '/desktop/settings', 'the save was attempted');
  assert.equal(p.signal('composer.mode'), 'auto', 'the VALUE every turn sends is the one the server still holds');
  assert.equal(p.signal('composer.mode.label'), 'Continue automatically', 'and the chip says the same');
  assert.equal(composer.isMode('auto'), true);
  assert.deepEqual(told.slice(-1), ['Not allowed here (loopback_only)'], 'and the refusal is told, never silent');
});

// ── desktop-turn (C3) ───────────────────────────────────────────────────────────────────────────────
test('a turn renders its answer, rides its verdict on it and moves the shared counters', async () => {
  const { p, chat } = composerPage();
  stubFetch(p, [response(200, {
    ok: true, answer: 'the slice is green', steps: 2, tokens: 1500, contextTokens: 800,
    closure: { verified: false, reasons: ['a step carries no evidence'] },
  })]);

  await p.desktop().turn.run('is it green?');

  assert.equal(chat.children[0].classList.contains('msg--agent'), true);
  assert.equal(chat.children[0].querySelector('[data-agent-body]').innerHTML, '<p>the slice is green</p>');
  const verdict = chat.children[0].querySelector('[data-agent-verdict]');
  assert.equal(verdict.hidden, false, 'the closure rides the answer instead of taking a line of its own');
  assert.equal(verdict.getAttribute('data-verified'), '0');
  assert.equal(verdict.querySelector('[data-verdict-tip]').textContent, 'The ledger disputes this turn — a step carries no evidence.');
  assert.equal(chat.children.length, 1, 'and no standalone claim was added');

  assert.equal(p.signal('session.turns'), 1);
  assert.equal(p.signal('session.steps'), 2);
  assert.equal(p.signal('session.tokens'), '1.50K', 'the provider\'s REAL count, not an estimate');
  assert.equal(p.signal('context.used'), '0.80K');
});

test('a turn with no answer to ride lands the claim as its own message, and a pause is said', async () => {
  const { p, chat } = composerPage();
  stubFetch(p, [
    response(200, { ok: true, closure: { verified: true, reasons: [] } }),
    response(200, { paused: true }),
  ]);

  await p.desktop().turn.run('close it');
  assert.equal(chat.children[0].classList.contains('msg--result'), true);
  assert.equal(chat.children[0].querySelector('[data-result-text]').textContent, 'verified');

  await p.desktop().turn.run('again');
  assert.equal(chat.children[1].textContent, 'The agent is waiting on your decision.', 'the hint is catalog copy');
});

test('the turn maps a 401 to the sign-in navigation and a 403 to a notice', async () => {
  const { p, chat, told } = composerPage();
  stubFetch(p, [response(401, { signin: '/webauthn/signin' }), response(403, { error: 'loopback_only' })]);

  p.desktop().turn.run('who is there?');
  await settle();

  assert.deepEqual(p.assigned, ['/webauthn/signin?next=%2Fdesktop'], 'the door asked for a session; the page goes and comes back');
  assert.deepEqual(told, [], 'a page that is leaving says nothing');
  assert.equal(chat.children.length, 0, 'nothing was painted over a page that is leaving');

  await p.desktop().turn.run('and now?');

  assert.deepEqual(told, ['Not allowed here (loopback_only)'], 'a refusal is told once, by the guard');
  assert.equal(chat.children[chat.children.length - 1].textContent, 'Not allowed here (loopback_only)', 'and the thread renders it');
});

test('a PARKED turn is said as a pause, not as a finished answer — and it does not count as a turn', async () => {
  // What the house really answers when the agent parks a question in ask mode, measured on the cattle:
  // `ok:true` AND `paused:true`, with the question as the answer and the way out as the hint. Reported by
  // the `ok && answer` branch it read as an ordinary answer and the session looked finished.
  const { p, chat } = composerPage();
  p.signal('session.turns', 3);
  stubFetch(p, [response(201, {
    ok: true, paused: true, answer: 'El agente quiere correr «agent_spawn». ¿Autorizas?',
    hint: 'contesta con: coa agent:answer --session=desk-1 --answer=<sí|no>', steps: 2, tokens: 17709,
  })]);

  await p.desktop().turn.run('create a file');

  assert.equal(chat.children[0].classList.contains('msg--agent'), true, 'what the agent asked is still said');
  assert.equal(chat.children[1].textContent, 'contesta con: coa agent:answer --session=desk-1 --answer=<sí|no>', 'and the pause with it');
  assert.equal(p.signal('session.turns'), 3, 'a parked turn has not closed, so it is not a completed turn');
  assert.equal(p.signal('session.steps'), 2, 'but the steps it really ran are real');
  assert.equal(p.signal('session.tokens'), '17.71K', 'and so are the tokens it really spent');
  assert.equal(p.signal('session.working'), false, 'the page is not running it any more; the human is');
});

test('the turn sets its OWN working state — a Desktop with no hub still shows a turn running', async () => {
  // The hub's `session.state` fact used to be the only writer, so with no hub wired the send button never
  // became a stop and the topbar kept reading «Ready» through a whole turn.
  const { p } = composerPage();
  const seen = [];
  let release = null;
  p.sandbox.fetch = () => new Promise((resolve) => { release = () => resolve(response(200, { ok: true, answer: 'done' })); });

  const running = p.desktop().turn.run('take your time');
  seen.push(p.signal('session.working'), p.signal('session.state.label'));

  release();
  await running;

  assert.deepEqual(seen, [true, 'Working'], 'the turn says it is working the moment it asks for one');
  assert.equal(p.signal('session.working'), false, 'and stops saying so when the answer lands');
  assert.equal(p.signal('session.state.label'), 'Idle', 'in the declared locale, from the catalog');
});

test('a turn the app refuses leaves nothing «working», and its state label is catalog copy', async () => {
  const { p, told } = composerPage();
  stubFetch(p, [new Error('network down')]);

  await p.desktop().turn.run('anyone there?');

  assert.deepEqual(told, ['The app could not be reached']);
  assert.equal(p.signal('session.working'), false, 'a failed turn is not a running one');
});

test('a 401 that names NO door still reaches sign-in, through the door the app declared', async () => {
  // Measured on the cattle: the Desktop's own routes answer `{"signin":"/webauthn/signin"}` on a 401, but
  // app-runtime's operation doors (`/agent`, `/agent/goal`, `/skill/invoke`) answer a bare
  // `MILPA_UNAUTHENTICATED` with no door in it. Before the fallback, a session that expired mid-page left
  // the human reading a raw runtime error and no way back in.
  const { p, chat } = composerPage({ doors: { signin: '/webauthn/signin' } });
  const told = [];
  p.desktop().onNotice((notice) => told.push(notice.text));
  stubFetch(p, [response(401, { error: '[MILPA_UNAUTHENTICATED] no session', code: 'MILPA_UNAUTHENTICATED' })]);

  p.desktop().turn.run('who is there?');
  await settle();

  assert.deepEqual(p.assigned, ['/webauthn/signin?next=%2Fdesktop'], 'the same door, from the page\'s own data');
  assert.deepEqual(told, [], 'a page that is leaving says nothing');
  assert.equal(chat.children.length, 0);
});

test('with NO door declared, a 401 that names none is reported rather than navigating nowhere', async () => {
  // A Desktop on the loopback gate declares no sign-in path, so `#milpa-desktop-guard` carries `""` and
  // the guard must not send anyone to a door this app has not got.
  const { p, told } = composerPage();
  stubFetch(p, [response(401, { error: '[MILPA_UNAUTHENTICATED] no session' })]);

  await p.desktop().turn.run('who is there?');

  assert.deepEqual(p.assigned, [], 'nowhere to go, so nowhere is where it goes');
  assert.deepEqual(told, ['[MILPA_UNAUTHENTICATED] no session'], 'and the human is told what the door said');
});

test('the hub\'s session state is the working signal, and it closes an open thinking block', () => {
  const { p, chat } = composerPage();

  p.bus().emit('agent.reasoning', { text: 'hmm' });
  p.bus().emit('session.state', { state: 'working' });

  assert.equal(p.signal('session.working'), true);
  assert.equal(p.signal('session.state.label'), 'Working');
  assert.equal(chat.children[0].getAttribute('data-thinking-active'), '1', 'still reasoning');

  p.bus().emit('session.state', { state: 'idle' });

  assert.equal(p.signal('session.working'), false);
  assert.equal(chat.children[0].getAttribute('data-thinking-active'), '0', 'the turn ended, so the block settled');
});

test('Stop signals the interrupt and says so; Regenerate re-runs the same prompt', async () => {
  const { p, composer, told } = composerPage();
  const calls = stubFetch(p, [response(200, { ok: true, answer: 'one' }), response(200, { ok: true, answer: 'two' })]);

  await p.desktop().turn.run('do it');
  p.signal('session.working', true);
  composer.stop();

  assert.equal(p.signal('session.working'), false);
  assert.deepEqual(told, ['stop requested']);

  await p.desktop().turn.regenerate();
  assert.deepEqual(JSON.parse(calls[1].init.body).prompt, 'do it', 'the same turn, asked for again');
});

// ── desktop-commands (C4) ───────────────────────────────────────────────────────────────────────────
test('only a REAL command is intercepted, and its parse keeps the argument text whole', () => {
  const { p } = composerPage();
  const commands = p.desktop().commands;

  assert.deepEqual({ ...commands.parse('/goal ship it  ') }, { name: 'goal', args: 'ship it' });
  assert.deepEqual({ ...commands.parse('/help') }, { name: 'help', args: '' });
  assert.equal(commands.parse('/nope'), null, 'a name the house never served is not a command');
  assert.equal(commands.parse('not a command at all'), null);
  assert.equal(commands.isBareUnknown('/nope'), true);
  assert.equal(commands.isBareUnknown('/nope with args'), false);
  assert.deepEqual([...commands.list().map((c) => c.name)], ['goal', 'mode', 'help']);
});

test('/goal reads the RESPONSE, never the request, and /help lists what the house serves', async () => {
  const { p, told } = composerPage();
  stubFetch(p, [
    response(200, { ok: true, goal: 'ship the slice', changed: true }),
    response(200, { ok: true, goal: '', changed: false }),
  ]);

  await p.desktop().commands.run({ name: 'goal', args: 'ship the slice' });
  assert.deepEqual(told, ['goal set: ship the slice']);

  await p.desktop().commands.run({ name: 'goal', args: '' });
  assert.deepEqual(told.slice(-1), ['no standing goal — /goal <text> sets one']);

  p.desktop().commands.run({ name: 'help', args: '' });
  assert.deepEqual(told.slice(-3), [
    '/goal <text> — Set the session goal',
    '/mode ask|acknowledge|auto — Change the permission mode',
    '/help — List the commands',
  ]);
});

test('an operation the app does not expose is REPORTED with what to do about it — never silently', async () => {
  const { p, told } = composerPage();
  stubFetch(p, [response(404, {}), response(200, { ok: false, error: 'the session has no goal to clear' })]);

  await p.desktop().commands.run({ name: 'goal', args: 'x' });
  assert.deepEqual(told, ['agent:goal → HTTP 404 — the app does not expose agent:goal over HTTP — expose the operation in config/http.php']);

  // A 2xx whose body says `ok:false` is the OP refusing, and it is reported as a refusal.
  await p.desktop().commands.run({ name: 'goal', args: 'clear' });
  assert.deepEqual(told.slice(-1), ['agent:goal refused — the session has no goal to clear']);
});

test('the completion popup opens on a name being typed, filters it, and its keys move and complete', () => {
  const { p, bar } = composerPage();
  const popup = p.document.getElementById('milpa-command-list');
  const commands = p.desktop().commands;
  const key = (name, extra = {}) => {
    let prevented = false;
    commands.handlesKey({ key: name, preventDefault() { prevented = true; }, ...extra });

    return prevented;
  };

  assert.equal(bar.field.getAttribute('aria-controls'), 'milpa-command-list', 'the field announces the popup it drives');

  type(bar, '/g');
  assert.equal(popup.getAttribute('data-open'), '1');
  assert.deepEqual([...popup.querySelectorAll('.milpa-cmd').filter((o) => !o.hidden).map((o) => o.getAttribute('data-command'))], ['goal']);
  assert.equal(bar.field.getAttribute('aria-activedescendant'), 'milpa-cmd-goal');

  type(bar, '/');
  assert.deepEqual(popup.querySelectorAll('.milpa-cmd').filter((o) => !o.hidden).length, 3, 'a bare slash offers them all');

  assert.equal(key('ArrowDown'), true);
  assert.equal(bar.field.getAttribute('aria-activedescendant'), 'milpa-cmd-mode', 'the arrows move the highlight');
  assert.equal(key('ArrowUp'), true);
  assert.equal(bar.field.getAttribute('aria-activedescendant'), 'milpa-cmd-goal');

  assert.equal(key('Tab'), true, 'Tab completes');
  assert.equal(bar.field.value, '/goal ', 'filled through the composer\'s own component');
  assert.equal(popup.getAttribute('data-open'), '0');

  // Enter on a name already typed in full is a SEND, not a completion: the popup hands the key back.
  type(bar, '/help');
  assert.equal(popup.getAttribute('data-open'), '1');
  assert.equal(key('Enter'), false, 'the composer sends it');
  assert.equal(popup.getAttribute('data-open'), '0');

  type(bar, '/g');
  assert.equal(key('Escape'), true);
  assert.equal(popup.getAttribute('data-open'), '0');
  assert.equal(bar.field.getAttribute('aria-activedescendant'), null);

  // Typing something that is not a name at all closes it too.
  type(bar, 'hello');
  assert.equal(popup.getAttribute('data-open'), '0');
  assert.equal(key('Enter'), false, 'a closed popup owns no key');
});

test('the popup the module drives is the one ON THE PAGE, even after the composer bar is re-rendered', () => {
  // The defect this pins, measured in a browser before the fix: the module resolved `#milpa-command-list`
  // ONCE at load and bound its listeners to that node, so a bar re-rendered by a plugin's
  // `desktop.composer_bar.after_render` (or by any live re-render) left it opening a DETACHED popup while
  // the visible one stayed shut. Nothing is cached and nothing is bound to the node, so this holds.
  const { p, bar } = composerPage();
  const stale = p.document.getElementById('milpa-command-list');
  const fresh = commandPopup(HOUSE_COMMANDS);
  stale.parent.appendChild(fresh);
  stale.remove();
  p.byId['milpa-command-list'] = fresh;

  type(bar, '/g');

  assert.equal(fresh.getAttribute('data-open'), '1', 'the popup on the page opened');
  assert.equal(stale.getAttribute('data-open'), '0', 'and the node that left the page did not');

  p.listeners.click.forEach((fn) => fn({ target: fresh.querySelector('[data-command="goal"]') }));
  assert.equal(bar.field.value, '/goal ', 'and its options still fill — the click is the document\'s');
});

test('a click on an option fills it, and the guard\'s ONE click-away closes the popup — unless it is the field', () => {
  const { p, bar } = composerPage();
  const popup = p.document.getElementById('milpa-command-list');
  // Both are the DOCUMENT's one click, the guard's: the module binds nothing to the popup node, so the
  // click that fills an option is the same listener as the click-away that closes it.
  const clickDocument = (target) => p.listeners.click.forEach((fn) => fn({ target }));

  type(bar, '/');
  clickDocument(popup.querySelector('[data-command="mode"]'));
  assert.equal(bar.field.value, '/mode ');
  assert.equal(popup.getAttribute('data-open'), '0');

  type(bar, '/');
  p.listeners.click.forEach((fn) => fn({ target: bar.field }));
  assert.equal(popup.getAttribute('data-open'), '1', 'a click in the field is the typist placing the caret');

  p.listeners.click.forEach((fn) => fn({ target: popup }));
  assert.equal(popup.getAttribute('data-open'), '0', 'a click anywhere else dismisses it');
});
