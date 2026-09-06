/*!
 * The Desktop's server-rendered surfaces, as the stub page's modules meet them (greenhouse decisions/0211).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * The declared views clone prototypes the SERVER printed and read data tags the SERVER served, so a test
 * that wants to run one has to hand it the same markup. This builds it: the message prototypes
 * (`MessagePrototypes`, `Live\Thinking`, `Live\AgentMessage`), the composer bar (`Live\ComposerBar`) and
 * the completion popup (`CommandListView`) — kept here, in one place, so two test files cannot drift into
 * two different ideas of what the shell renders.
 *
 * IT IS STILL A SECOND IDEA of the renderers' markup, and green here is not evidence about the served
 * page. What holds the two together is `Milpa\DesktopApp\Tests\DomContractTest`, which reads every `#id`
 * and `[data-…]` literal the shipped modules reach for out of the modules themselves and asserts the
 * SERVER prints each one — so a renamed hook in a renderer fails there even while this file is stale.
 */
import { El, page, template } from './page.mjs';

/** The message components' prototypes, by the template id the shell prints them in. */
export function prototypes() {
  const user = new El('div', { class: 'msg msg--user' });
  const userInner = user.appendChild(new El('div'));
  userInner.appendChild(new El('span', { class: 'msg__meta', text: 'you · now' }));
  userInner.appendChild(new El('p', { 'data-user-body': '' }));

  const agent = new El('div', { class: 'msg msg--agent' });
  agent.appendChild(new El('span', { class: 'msg__meta', text: 'agent · local' }));
  agent.appendChild(new El('div', { class: 'msg__md', 'data-agent-body': '' }));
  const tools = agent.appendChild(new El('div', { class: 'msg__tools' }));
  tools.appendChild(new El('button', { class: 'msg__tool-btn', 'data-agent-copy': '' }));
  tools.appendChild(new El('button', { class: 'msg__tool-btn', 'data-agent-regenerate': '' }));
  const verdict = tools.appendChild(new El('span', { class: 'msg__verdict', 'data-agent-verdict': '', 'data-verified': '1', hidden: true }));
  verdict.appendChild(new El('span', { 'data-verdict-mark': '', text: '✓' }));
  verdict.appendChild(new El('span', { 'data-verdict-label': '', text: 'verified' }));
  verdict.appendChild(new El('span', { 'data-verdict-tip': '' }));

  const tool = new El('div', { class: 'msg msg--tool', 'data-open': '0' });
  const head = tool.appendChild(new El('button', { class: 'msg__tool-head', 'data-tool-toggle': '' }));
  head.appendChild(new El('span', { class: 'msg__tool-name', 'data-tool-name': '' }));
  head.appendChild(new El('span', { class: 'msg__tool-summary', 'data-tool-summary': '' }));
  tool.appendChild(new El('pre', { class: 'msg__tool-raw', 'data-tool-body': '' }));

  const task = new El('div', { class: 'msg msg--task' });
  const taskInner = task.appendChild(new El('div'));
  taskInner.appendChild(new El('span', { class: 'msg__title', 'data-task-title': '' }));
  taskInner.appendChild(new El('span', { class: 'mui-badge', 'data-task-status': '', text: 'todo' }));

  // The system notice carries its region ON the root — the case that painted empty bubbles until the
  // thread's `region()` learned to ask both ways.
  const system = new El('div', { class: 'msg msg--system', 'data-system-body': '' });

  const result = new El('div', { class: 'msg msg--result', 'data-verified': '1' });
  result.appendChild(new El('span', { class: 'msg__result-mark', 'data-result-mark': '', text: '✓' }));
  result.appendChild(new El('span', { 'data-result-text': '', text: 'verified' }));
  result.appendChild(new El('span', { class: 'msg__result-tip', 'data-result-tip': '' }));

  const thinking = new El('div', { class: 'msg msg--thinking milpa-think', 'data-open': '1', 'data-thinking-active': '1' });
  const toggle = thinking.appendChild(new El('button', { class: 'milpa-think__toggle', 'data-thinking-toggle': '', 'data-thinking-head': '' }));
  // The spark and the dots are the component's own — the elapsed replaces the LABEL and must not eat them.
  toggle.appendChild(new El('span', { class: 'milpa-think__spark', 'data-thinking-spark': '', text: '◈' }));
  toggle.appendChild(new El('span', { class: 'milpa-think__label', 'data-thinking-label': '', text: 'thinking' }));
  toggle.appendChild(new El('span', { class: 'milpa-think__dots' }));
  thinking.appendChild(new El('div', { class: 'milpa-think__body', 'data-thinking-body': '' }));

  return {
    'milpa-user-msg-proto': user,
    'milpa-agent-msg-proto': agent,
    'milpa-tool-msg-proto': tool,
    'milpa-task-msg-proto': task,
    'milpa-system-msg-proto': system,
    'milpa-result-msg-proto': result,
    'milpa-thinking-proto': thinking,
  };
}

/** Those prototypes as the `<template>` tags the page carries, by id. */
export function prototypeTags() {
  const tags = {};
  for (const [id, root] of Object.entries(prototypes())) { tags[id] = template(id, root); }

  return tags;
}

/** The composer bar as `Live\ComposerBar` prints it: the field, the count, the mode menu, the send. */
export function composerBar() {
  const wrap = new El('div', { class: 'composer-wrap' });
  const box = wrap.appendChild(new El('div', { class: 'milpa-composer-box' }));
  const field = box.appendChild(new El('textarea', { id: 'composer-input', value: '' }));
  const foot = box.appendChild(new El('div'));
  foot.appendChild(new El('span', { id: 'milpa-charcount' }));
  const menu = foot.appendChild(new El('div', { id: 'milpa-mode-menu', hidden: true }));
  for (const [mode, label] of [['ask', 'Ask before changing'], ['acknowledge', 'Compatibility'], ['auto', 'Continue automatically']]) {
    menu.appendChild(new El('button', { class: 'milpa-mode-opt', 'data-mode': mode, 'data-label': label }));
  }
  foot.appendChild(new El('button', { id: 'milpa-send' }));

  return { wrap, field, menu };
}

/** The completion popup as `CommandListView` prints it, for the commands the house serves. */
export function commandPopup(commands) {
  const popup = new El('div', { id: 'milpa-command-list', class: 'milpa-cmds', 'data-open': '0' });
  commands.forEach((command) => {
    popup.appendChild(new El('button', {
      id: 'milpa-cmd-' + command.name,
      class: 'milpa-cmd',
      'data-command': command.name,
      'data-kind': command.kind,
      'aria-selected': 'false',
    }));
  });

  return popup;
}

/**
 * The Capabilities screen as `Live\CapabilitiesScreen` prints it, with ONE available capability.
 *
 * The confirm box is NOT built here: it is the server-rendered `<template>` the module clones, which is
 * the whole point of phase D2 — a box that used to exist only inside a JavaScript string.
 */
export function capabilitiesScreen(pkg = 'milpa/data') {
  const root = new El('div', { class: 'view milpa-capabilities', 'data-view': 'capabilities' });
  const grid = root.appendChild(new El('div', { class: 'cap-grid' }));
  const card = grid.appendChild(new El('div', { class: 'cap-card', 'data-cap-row': pkg }));
  const head = card.appendChild(new El('div', { class: 'cap-card__head' }));
  head.appendChild(new El('span', { class: 'cap-card__name', text: pkg }));
  const enable = head.appendChild(new El('button', {
    class: 'mui-btn', 'data-cap-enable': pkg, 'data-cap-cmd': 'composer require ' + pkg, text: 'Enable',
  }));

  const box = new El('div', { class: 'cap-confirm' });
  box.appendChild(new El('p', { class: 'cap-confirm__cmd', 'data-cap-cmd-text': '' }));
  const row = box.appendChild(new El('div', { class: 'cap-confirm__row', 'data-cap-actions': '' }));
  row.appendChild(new El('button', { 'data-cap-go': '', text: 'Confirm' }));
  row.appendChild(new El('button', { 'data-cap-cancel': '', text: 'Cancel' }));

  return { root, card, enable, proto: template('milpa-cap-confirm-proto', box) };
}

/** The Work board as `Live\WorkBoard` prints it: two columns, one draggable card in the first. */
export function workBoard(session = 's1') {
  const board = new El('div', { class: 'work-board', 'data-session': session });
  const pending = board.appendChild(new El('section', { class: 'work-col', 'data-status': 'pending' }));
  const done = board.appendChild(new El('section', { class: 'work-col', 'data-status': 'done' }));
  const card = pending.appendChild(new El('article', { class: 'mui-card work-card', draggable: 'true', 'data-index': '0' }));

  return { board, pending, done, card };
}

/** The Preview screen as `Live\ScreenPreview` prints it: the name box, the button, one chip, the frame. */
export function screensScreen(route = '/live') {
  const root = new El('div', { class: 'view milpa-screens', 'data-view': 'preview' });
  const name = root.appendChild(new El('input', { id: 'milpa-preview-name', value: '' }));
  root.appendChild(new El('button', { id: 'milpa-preview-go', 'data-live-route': route, text: 'Preview' }));
  const chips = root.appendChild(new El('span', { id: 'milpa-screens' }));
  const chip = chips.appendChild(new El('button', {
    class: 'screen-chip', 'data-screen-name': 'board', 'data-screen-src': route + '/page?component=board',
  }));
  const frame = root.appendChild(new El('iframe', { id: 'milpa-preview-frame' }));

  return { root, name, chip, frame };
}

/** The decisions inbox as `Live\DecisionsInbox` prints it: the always-present list and its empty line. */
export function decisionsScreen() {
  const root = new El('div', { class: 'view milpa-decisions', 'data-view': 'decisions' });
  const list = root.appendChild(new El('ol', { id: 'milpa-decisions-list' }));
  root.appendChild(new El('p', { class: 'mui-empty', id: 'milpa-decisions-empty' }));

  const card = new El('li', { class: 'decision-card' });
  card.appendChild(new El('p', { class: 'decision-card__q', 'data-decision-question': '' }));
  card.appendChild(new El('p', { class: 'decision-card__facts', 'data-decision-facts': '' }));

  return { root, list, proto: template('milpa-decision-proto', card) };
}

/** The sidebar's decisions nav row, with or without the badge the server renders when one is waiting. */
export function decisionsNav({ badge = null } = {}) {
  const item = new El('a', { class: 'mui-sidebar__item', 'data-nav': 'decisions' });
  if (badge !== null) {
    item.appendChild(new El('span', { class: 'mui-sidebar__item-badge mui-badge', text: String(badge) }));
  }

  return item;
}

/** The house's own commands, as `DesktopData::houseCommands()` serves them. */
export const HOUSE_COMMANDS = [
  { name: 'goal', kind: 'house', description: 'Set the session goal', usage: '/goal <text>', method: 'POST' },
  { name: 'mode', kind: 'house', description: 'Change the permission mode', usage: '/mode ask|acknowledge|auto', method: 'POST' },
  { name: 'help', kind: 'house', description: 'List the commands', usage: '/help', method: 'GET' },
];

/**
 * A page carrying the whole conversation half of the shell: the thread, the prototypes, the composer bar,
 * the completion popup and the data tags the modules read (the command list, the agent session, and the
 * guard's doors when the test asks for them).
 */
export function shellPage({ commands = HOUSE_COMMANDS, session = 'desk-0123456789abcdef', modules = [], doors = null } = {}) {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat', class: 'tabpane milpa-chat' }));
  const bar = composerBar();
  html.appendChild(bar.wrap);
  html.appendChild(commandPopup(commands));

  const elements = prototypeTags();
  elements['milpa-commands'] = new El('script', { id: 'milpa-commands', text: JSON.stringify(commands) });
  elements['milpa-desktop-session'] = new El('script', { id: 'milpa-desktop-session', text: JSON.stringify({ agent: session }) });
  // The doors the guard falls back to, as `ShellController::guardJson()` writes them: present only when
  // this Desktop really stands behind the passkey gate.
  if (doors) { elements['milpa-desktop-guard'] = new El('script', { id: 'milpa-desktop-guard', text: JSON.stringify(doors) }); }

  const p = page({ tree: html, elements, bus: true, modules });

  return { p, html, chat, bar };
}
