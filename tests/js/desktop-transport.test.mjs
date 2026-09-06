/*!
 * The Desktop's transport, as the browser runs it (greenhouse decisions/0211, phase D1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * Two modules that used to be two inline `<script>` tags in the shell: the BUS (`window.MilpaShell`, the
 * published extension point five modules and every plugin panel reach for) and the HUB connector (the one
 * `EventSource`, and the translation of what arrives on it into the shell's own facts).
 *
 * The tests below run the SHIPPED files — no bundler, no jsdom — so what they exercise is the file the
 * browser gets.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
// Objects a MODULE built live in the `node:vm` realm, so their prototype is not this realm's: a strict
// deep-equal would compare prototypes and fail on values that are identical. The loose one is the right
// instrument for a fact that crossed the boundary — the strict one stays for everything else.
import { deepEqual as sameShape } from 'node:assert';
import { El, page } from './support/page.mjs';

/** A page with the real bus and the real connector, and the hub tag the server would have written. */
function transport({ url = '', tree = null, modules = [] } = {}) {
  const elements = { 'milpa-desktop-hub': new El('script', { id: 'milpa-desktop-hub', text: JSON.stringify(url === '' ? {} : { url }) }) };

  return page({ tree: tree || new El('html'), elements, modules: ['desktop-shell-bus', 'desktop-hub', ...modules] });
}

test('the bus publishes to its own subscribers and to the any-handlers, and one deaf consumer silences nobody', () => {
  const p = transport();
  const bus = p.sandbox.MilpaShell;
  const seen = [];
  const all = [];

  bus.on('gate.opened', () => { throw new Error('a subscriber that throws'); });
  bus.on('gate.opened', (fact) => seen.push(fact));
  bus.onAny((type, fact) => all.push([type, fact]));
  bus.emit('gate.opened', { operation: 'fs:write' });

  assert.deepEqual(seen, [{ operation: 'fs:write' }], 'the second subscriber still ran');
  assert.deepEqual(all, [['gate.opened', { operation: 'fs:write' }]], 'and so did the any-handler');
});

test('the transport state is a SIGNAL, so the status bar binds it instead of being poked', () => {
  const p = transport();
  const bus = p.sandbox.MilpaShell;
  const told = [];
  bus.onStatus((state) => told.push(state));

  bus.status('live');
  assert.equal(p.signal('conn.state'), 'live');
  assert.equal(p.signal('conn.label'), '◉ live', 'the words come from the catalog, not from the module');

  bus.status('offline');
  assert.equal(p.signal('conn.state'), 'offline');
  assert.equal(p.signal('conn.label'), '○ offline');
  assert.deepEqual(told, ['live', 'offline'], 'a plugin that tracks the connection still hears it');
});

test('a contributed panel is reachable by id — the plugin DX the bus exists for', () => {
  const html = new El('html');
  const panel = html.appendChild(new El('section', { 'data-panel': 'sessions' }));
  const body = panel.appendChild(new El('div', { 'data-panel-body': '' }));
  const p = transport({ tree: html });

  assert.equal(p.sandbox.MilpaShell.panel('sessions'), body);
  assert.equal(p.sandbox.MilpaShell.panel('nobody'), null);
});

test('a desktop ShellEvent is republished unchanged; an unknown envelope is ignored', () => {
  const p = transport();
  const seen = [];
  p.sandbox.MilpaShell.on('gate.opened', (fact) => seen.push(fact));

  assert.equal(p.desktop().hub.translate({ event: 'gate.opened', data: { operation: 'fs:write' } }), 'event');
  assert.deepEqual(seen, [{ operation: 'fs:write' }]);
  assert.equal(p.desktop().hub.translate({ nothing: true }), '', 'a shape this Desktop does not know is not a fact');
  assert.equal(p.desktop().hub.translate(null), '');
});

test("a governed turn's projection is translated into the facts the shell already renders", () => {
  const p = transport();
  const facts = [];
  p.sandbox.MilpaShell.onAny((type, data) => facts.push([type, data]));
  p.signal('session.tool_calls', 2);

  const hub = p.desktop().hub;
  hub.translate({ kind: 'activity', activity: { state: 'thinking' } });
  hub.translate({ kind: 'reasoning', reasoning: { delta: 'weighing…' } });
  hub.translate({ kind: 'message', message: { content: 'done' } });
  hub.translate({ kind: 'activity', activity: { state: 'tool', detail: 'fs:read', result: '{}' } });
  hub.translate({ kind: 'activity', activity: { state: 'ready' } });

  assert.deepEqual(facts.map(([type]) => type), [
    'session.state', 'agent.reasoning', 'agent.message', 'tool.call', 'session.state',
  ]);
  sameShape(facts[0][1], { state: 'working' });
  sameShape(facts[1][1], { text: 'weighing…' });
  sameShape(facts[2][1], { text: 'done' });
  sameShape(facts[3][1], { name: 'fs:read', result: '{}' });
  sameShape(facts[4][1], { state: 'idle' });
  assert.equal(p.signal('session.tool_calls'), 3, 'a tool that ran is counted into the shared signal');
});

test('a parked question becomes TWO facts — a notice and decision.parked — and touches no DOM', () => {
  const p = transport();
  const facts = [];
  p.sandbox.MilpaShell.onAny((type, data) => facts.push([type, data]));

  assert.equal(p.desktop().hub.translate({ kind: 'waiting', ended: { question: 'May I write?' } }), 'session');

  sameShape(facts, [
    ['system.notice', { text: 'Waiting on you: May I write?' }],
    ['decision.parked', { question: 'May I write?' }],
  ]);
});

test('with a hub wired the module opens ONE stream, and reports live / offline on it', () => {
  const opened = [];
  const p = transport({ url: 'https://hub.example/.well-known/mercure?topic=desktop%2Fshell' });
  p.sandbox.EventSource = function (url, options) { opened.push([url, options]); this.url = url; };

  const stream = p.desktop().hub.open();

  assert.equal(opened.length, 1, 'one connection, not one per topic');
  assert.equal(opened[0][0], 'https://hub.example/.well-known/mercure?topic=desktop%2Fshell');
  assert.equal(opened[0][1].withCredentials, true, 'the hub reads the subscriber JWT from the cookie');

  const seen = [];
  p.sandbox.MilpaShell.on('agent.message', (fact) => seen.push(fact));
  stream.onopen();
  assert.equal(p.signal('conn.state'), 'live');
  stream.onmessage({ data: JSON.stringify({ kind: 'message', message: { content: 'hello' } }) });
  sameShape(seen, [{ text: 'hello' }]);
  stream.onmessage({ data: 'not json' });
  sameShape(seen, [{ text: 'hello' }], 'a malformed frame is dropped, not thrown');
  stream.onerror();
  assert.equal(p.signal('conn.state'), 'offline');
});

test('with no hub wired nothing is opened and the bar settles on offline', () => {
  const p = transport();
  p.sandbox.EventSource = function () { throw new Error('nothing must be opened'); };

  assert.equal(p.desktop().hub.url(), '');
  assert.equal(p.desktop().hub.open(), null);
  assert.equal(p.signal('conn.state'), 'offline');
  assert.equal(p.signal('conn.label'), '○ offline');
});

test('the stream is opened on DOMContentLoaded — after every deferred module has subscribed', () => {
  const opened = [];
  const p = transport({ url: 'https://hub.example/x' });
  p.sandbox.EventSource = function (url) { opened.push(url); };

  assert.deepEqual(opened, [], 'loading the module opens nothing');
  p.listeners['DOMContentLoaded'].forEach((fn) => fn());
  assert.deepEqual(opened, ['https://hub.example/x']);
});
