/**
 * The conversation and its message components, measured by EXECUTION (greenhouse decisions/0211, phase C1).
 *
 * The thread and every message KIND are declared views now, so what these run is the shipped file: the
 * prototypes the server renders are handed to the stub page as real `<template>` tags, the modules clone
 * and fill them, and what is asserted is the DOM a browser would end up with.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, settle } from './support/page.mjs';
import { prototypeTags } from './support/shell.mjs';

/** A page with the thread, its prototypes and every message module the shell declares. */
function thread(extra = {}) {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat', class: 'tabpane milpa-chat' }));
  const p = page({
    tree: html,
    elements: prototypeTags(),
    bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim'],
    ...extra,
  });

  return { p, html, chat, conversation: () => p.desktop().conversation };
}

test('every message kind lands as a clone of ITS OWN component prototype, filled by that component', () => {
  const { p, chat, conversation } = thread();
  const conv = conversation();

  conv.append('user', { text: 'ship the slice' });
  conv.append('agent', { text: 'Done. **Shipped**.' });
  conv.append('tool', { name: 'ledger:read', result: '{"ok":true,"rows":3}' });
  conv.append('task', { title: 'Measure in a browser', status: 'doing' });
  conv.append('system', { text: 'the door answered 403' });
  conv.append('result', { verified: false, reasons: 'a step carries no evidence' });

  assert.equal(chat.children.length, 6, 'six messages, each its own component');
  assert.equal(chat.children[0].classList.contains('msg--user'), true);
  assert.equal(chat.children[0].querySelector('[data-user-body]').textContent, 'ship the slice');

  // The agent's answer is MARKDOWN the component rendered from escaped text.
  assert.equal(
    chat.children[1].querySelector('[data-agent-body]').innerHTML,
    '<p>Done. <strong>Shipped</strong>.</p>',
  );

  // A tool result reads as machinery made legible: a summary, and the raw pretty-printed under the fold.
  assert.equal(chat.children[2].querySelector('[data-tool-name]').textContent, 'ledger:read');
  assert.equal(chat.children[2].querySelector('[data-tool-summary]').textContent, '→ ok · 2 fields');
  assert.equal(chat.children[2].querySelector('[data-tool-body]').textContent, '{\n  "ok": true,\n  "rows": 3\n}');

  assert.equal(chat.children[3].querySelector('[data-task-title]').textContent, 'Measure in a browser');
  assert.equal(chat.children[3].querySelector('[data-task-status]').textContent, 'doing');

  // The system notice's region is the ROOT — the fill that painted empty bubbles before `region()`.
  assert.equal(chat.children[4].textContent, 'the door answered 403');

  // The claim is a judgement with a state, and the same sentence reaches a screen reader.
  const claim = chat.children[5];
  assert.equal(claim.getAttribute('data-verified'), '0');
  assert.equal(claim.querySelector('[data-result-mark]').textContent, '⚠');
  assert.equal(claim.querySelector('[data-result-text]').textContent, 'disputed');
  assert.equal(claim.querySelector('[data-result-tip]').textContent, 'The ledger disputes this turn — a step carries no evidence.');
  assert.equal(claim.getAttribute('aria-label'), 'Disputed. The ledger disputes this turn — a step carries no evidence.');

  // An unknown kind is not silently dropped: it lands as a system notice.
  conv.append('nonsense', { text: 'from a plugin that guessed' });
  assert.equal(chat.children[6].classList.contains('msg--system'), true);
  assert.equal(p.warnings.length, 0);
});

test('a fenced code block survives markdown as ESCAPED code, not as markup', () => {
  const { p } = thread();
  const markdown = p.desktop().messages.agent.markdown;

  assert.equal(
    markdown('before\n```js\nvar x = "<b>";\n```\nafter'),
    '<p>before</p><pre class="md-pre"><code>var x = "&lt;b&gt;";</code></pre><p>after</p>',
  );
  assert.equal(markdown('<script>alert(1)</script>'), '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', 'the model\'s output is never raw HTML');
});

test('the guard says what a door answered and the THREAD renders it — nothing else couples them', () => {
  const { p, chat } = thread();

  p.desktop().notice('error', 'Not allowed here (loopback_only)');

  assert.equal(chat.children.length, 1);
  assert.equal(chat.children[0].classList.contains('msg--system'), true);
  assert.equal(chat.children[0].textContent, 'Not allowed here (loopback_only)');
  assert.equal(p.signal('desktop.notice').text, 'Not allowed here (loopback_only)', 'and it is a signal any surface may read');
});

test('the stream renders in the voice of each kind, and the thinking block opens, streams and closes', () => {
  const { p, chat } = thread();
  const bus = p.bus();

  bus.emit('agent.reasoning', { text: 'weighing ' });
  bus.emit('agent.reasoning', { text: 'the options' });

  assert.equal(chat.children.length, 1, 'one block for the whole reasoning');
  const block = chat.children[0];
  assert.equal(block.classList.contains('milpa-think'), true);
  assert.equal(block.querySelector('[data-thinking-body]').textContent, 'weighing the options');
  assert.equal(block.getAttribute('data-thinking-active'), '1', 'the pulse is on while it reasons');

  bus.emit('agent.message', { text: 'here it is' });

  assert.equal(block.getAttribute('data-thinking-active'), '0', 'the answer closes the block');
  assert.equal(block.getAttribute('data-open'), '0');
  assert.match(block.querySelector('[data-thinking-label]').textContent, /^thought for \d+s$/);
  assert.equal(chat.children[1].classList.contains('msg--agent'), true);

  bus.emit('tool.call', { name: 'fs:read', result: '[1,2,3]' });
  bus.emit('task.added', { title: 'Write it up' });
  bus.emit('system.notice', { text: 'a fact' });

  assert.equal(chat.children[2].querySelector('[data-tool-summary]').textContent, '→ 3 items');
  assert.equal(chat.children[3].querySelector('[data-task-status]').textContent, 'todo');
  assert.equal(chat.children[4].textContent, 'a fact');
});

test('ONE delegated click serves every message component: the collapses, Copy and Regenerate', async () => {
  const { p, chat } = thread();
  const conv = conversation(p);
  const asked = [];
  p.desktop().turn = { regenerate: () => asked.push('regenerate') };

  p.bus().emit('agent.reasoning', { text: 'mm' });
  const block = chat.children[0];
  const view = p.mount('desktopConversation', undefined, chat);

  view.onClick({ target: block.querySelector('[data-thinking-toggle]') });
  assert.equal(block.getAttribute('data-open'), '0', 'the thinking block collapses');
  view.onClick({ target: block.querySelector('[data-thinking-toggle]') });
  assert.equal(block.getAttribute('data-open'), '1');

  conv.append('tool', { name: 't', result: 'x' });
  const tool = chat.children[1];
  view.onClick({ target: tool.querySelector('[data-tool-toggle]') });
  assert.equal(tool.getAttribute('data-open'), '1', 'the raw result opens');

  conv.append('agent', { text: 'an answer' });
  const answer = chat.children[2];
  const copy = answer.querySelector('[data-agent-copy]');
  view.onClick({ target: copy });
  assert.equal(copy.classList.contains('is-done'), true, 'Copy says it copied');

  view.onClick({ target: answer.querySelector('[data-agent-regenerate]') });
  assert.deepEqual(asked, ['regenerate'], 'Regenerate asks the TURN, it does not re-derive one');

  // A click on nothing in particular is nobody's.
  assert.equal(view.onClick({ target: chat }), undefined);
  await settle();
});

/** The conversation API of a page built by `thread()`. */
function conversation(p) { return p.desktop().conversation; }

test('the verdict RIDES the last answer, and falls back to a standalone claim when there is none', () => {
  const { p, chat } = thread();
  const conv = conversation(p);

  assert.equal(conv.verdict(true, ''), false, 'with no answer to ride, the caller is told so');

  conv.append('agent', { text: 'first' });
  conv.append('agent', { text: 'second' });
  assert.equal(conv.verdict(true, ''), true);

  const slot = chat.children[1].querySelector('[data-agent-verdict]');
  assert.equal(slot.hidden, false, 'the LAST answer carries it');
  assert.equal(slot.getAttribute('data-verified'), '1');
  assert.equal(slot.querySelector('[data-verdict-label]').textContent, 'verified');
  assert.equal(
    slot.querySelector('[data-verdict-tip]').textContent,
    'The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact\'s latest check is red.',
  );
  assert.equal(chat.children[0].querySelector('[data-agent-verdict]').hidden, true, 'the earlier answer is untouched');
});
