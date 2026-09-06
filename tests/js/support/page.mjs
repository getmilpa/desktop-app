/*!
 * A stub page for the Desktop's declared client modules (greenhouse decisions/0211).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * No build, no bundler, no jsdom: the shipped files are loaded VERBATIM into a `node:vm` context whose
 * `document` implements only what they actually ask of it — the selector forms, the properties and the
 * events the modules use. That is the point of the harness: what these tests exercise is the file the
 * browser gets, not a transpiled copy of it.
 *
 * Alpine is a stub that records every `Alpine.data(name, factory)` the runtime hands it, so a test can
 * take a component's factory, give it the `$store` and `$root` Alpine would, and RUN it.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

/** The framework runtime, verbatim from the installed package — the same file the page serves. */
export const LOCAL_RUNTIME = path.join(root, 'vendor/milpa/live-web/resources/milpa-live.js');

/** One declared component's client module, verbatim from the package. */
export const moduleFile = (component) => path.join(root, 'resources/components', component, `${component}.js`);

/** The signals the server seeds into the page, as `#milpa-live-signals` — the store the modules read. */
export const SIGNALS = {
  'desktop.nav': 'sessions',
  'desktop.tab': 'chat',
  'desktop.gate.open': false,
  'desktop.auth.open': false,
  'session.working': false,
  'composer.draft': false,
  'composer.panel': '',
  'ui.dismiss': 0,
  'ui.theme': 'system',
  'desktop.notice': null,
  'settings.saved': null,
};

/**
 * The Desktop's copy, as the page hands it to the guard — the ENGLISH values of `src/I18n/Catalog.php`,
 * verbatim.
 *
 * It is a copy because this harness runs without PHP, and a copy is a place two truths can drift: this
 * one had already drifted («The call failed (%s)» for the shipped «The request failed (HTTP %s)»), which
 * would let a test assert a sentence no user ever sees. `Milpa\DesktopApp\Tests\I18n\ClientCopyTest`
 * closes it from the PHP side — it fails if any entry here disagrees with the catalog, and if any
 * `tr('…')` key the shipped modules use is missing from it.
 */
export const CATALOG = {
  'guard.forbidden': 'Not allowed here',
  'guard.forbidden.reason': 'Not allowed here (%s)',
  'guard.failed': 'The request failed (HTTP %s)',
  'guard.unreachable': 'The app could not be reached',
  'settings.saved': 'Saved',
  'settings.save_failed': 'Not saved (HTTP %s)',
  'enroll.none': 'No passkey door in this app',
  'verdict.verified': 'verified',
  'verdict.disputed': 'disputed',
  'verdict.backed': 'The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact\'s latest check is red.',
  'verdict.disputed.why': 'The ledger disputes this turn — %s.',
  'verdict.disputed.default': 'the completion is not backed by evidence',
  'verdict.aria.verified': 'Verified. %s',
  'verdict.aria.disputed': 'Disputed. %s',
  'thinking.elapsed': 'thought for %ss',
  'turn.paused': 'The agent is waiting on your decision.',
  'turn.stop_requested': 'stop requested',
  'session.state.working': 'Working',
  'session.state.idle': 'Idle',
  'preview.failed': 'The wire does not serve «%s» (HTTP %s)',
  'preview.unreachable': 'The wire could not be reached for «%s»',
  'composer.tokens': '~%s tokens',
  'command.unknown': 'unknown command %s — commands: %s',
  'command.mode.usage': 'usage: /mode ask|acknowledge|auto',
  'command.mode.set': 'mode %s — applies from the next turn',
  'command.goal.set': 'goal set: %s',
  'command.goal.none': 'no standing goal — /goal <text> sets one',
  'op.refused': '%s refused — %s',
  'op.no_reason': 'no reason given',
  'op.failed': '%s → HTTP %s — %s',
  'op.detail': '%s (%s)',
  'op.hint.not_exposed': 'the app does not expose %s over HTTP — expose the operation in config/http.php',
  'conn.live': '◉ live',
  'conn.offline': '○ offline',
  'conn.connecting': '○ connecting…',
  'hub.waiting': 'Waiting on you: %s',
  'cap.working': 'Working…',
  'cap.done': 'Installed %s — reloading…',
  'cap.failed': 'Could not install %s — %s',
  'cap.refused': 'refused',
  'cap.no_token': 'the house issued no confirm token',
  'decisions.just_now': 'just now · open the conversation to answer',
  'decisions.unnamed': 'A question is waiting for you.',
};

/**
 * A `<template>` as the server rendered one: its `content` is a fragment holding the prototype's root.
 *
 * The message components are CLONED from these, so a test that wants a kind to land in the thread hands
 * the page the same tag the shell prints.
 */
export function template(id, root) {
  const tag = new El('template', { id });
  tag.content = new El('#fragment');
  tag.content.appendChild(root);

  return tag;
}

/** The `MilpaShell` bus, as the page's inline runtime provides it — installed BEFORE the modules load. */
export function busStub() {
  const byType = {};
  const any = [];
  const statuses = [];

  return {
    on: (type, cb) => { (byType[type] ||= []).push(cb); },
    onAny: (cb) => { any.push(cb); },
    onStatus: (cb) => { statuses.push(cb); },
    emit(type, data) { (byType[type] || []).forEach((cb) => cb(data)); any.forEach((cb) => cb(type, data)); },
    status(state) { statuses.forEach((cb) => cb(state)); },
  };
}

/** One simple selector step: `#id`, `.class`, `tag`, `[attr]`, `[attr="value"]`, `:checked`. */
function matchesSimple(el, selector) {
  let rest = selector;
  let ok = true;
  const tag = /^[a-z][a-z0-9-]*/i.exec(rest);
  if (tag) {
    ok = ok && el.tag === tag[0];
    rest = rest.slice(tag[0].length);
  }
  for (const part of rest.match(/#[^.#[:]+|\.[^.#[:]+|\[[^\]]+\]|:checked/g) || []) {
    if (part.startsWith('#')) { ok = ok && el.id === part.slice(1); continue; }
    if (part.startsWith('.')) { ok = ok && el.classList.contains(part.slice(1)); continue; }
    if (part === ':checked') { ok = ok && el.checked === true; continue; }
    const attr = /^\[([^=\]]+)(?:="([^"]*)")?\]$/.exec(part);
    if (attr) {
      const value = el.getAttribute(attr[1]);
      ok = ok && (attr[2] === undefined ? value !== null : value === attr[2]);
    }
  }

  return ok;
}

/** Descendant selectors only (`a b c`): every step but the last is an ancestor filter. */
function matches(el, selector) {
  const steps = selector.trim().split(/\s+/);
  if (!matchesSimple(el, steps[steps.length - 1])) { return false; }
  let node = el.parent;
  for (let i = steps.length - 2; i >= 0; i--) {
    while (node && !matchesSimple(node, steps[i])) { node = node.parent; }
    if (!node) { return false; }
    node = node.parent;
  }

  return true;
}

/** The slice of the DOM the modules touch: attributes, classes, children, text, value, events. */
export class El {
  constructor(tag = 'div', attrs = {}) {
    this.tag = tag;
    this.attrs = { ...attrs };
    this.children = [];
    this.parent = null;
    this.listeners = {};
    this.textContent = attrs.text || '';
    this.hidden = attrs.hidden !== undefined && attrs.hidden !== false;
    this.id = attrs.id || '';
    this.checked = attrs.checked === true;
    this.value = attrs.value === undefined ? '' : attrs.value;
    const self = this;
    this.classList = {
      contains: (c) => (self.attrs.class || '').split(/\s+/).includes(c),
      add(c) { if (!self.classList.contains(c)) { self.attrs.class = ((self.attrs.class || '') + ' ' + c).trim(); } },
      remove(c) { self.attrs.class = (self.attrs.class || '').split(/\s+/).filter((x) => x !== c).join(' '); },
      toggle(c, on) { if (on === undefined ? !self.classList.contains(c) : on) { self.classList.add(c); } else { self.classList.remove(c); } },
    };
  }

  /** Whether this element matches one simple selector — what a module's `region()` asks of a clone. */
  matches(selector) { return matches(this, selector); }
  /** This element or the nearest ancestor that matches — how every delegated handler reads a click. */
  closest(selector) {
    let node = this;
    while (node) {
      if (matches(node, selector)) { return node; }
      node = node.parent;
    }

    return null;
  }
  /** The focus a composer gives back to its field; the harness only needs to record that it was asked. */
  focus() { this.focused = true; }
  /** A deep copy, as `template.content.cloneNode(true)` hands one over. */
  cloneNode(deep = false) {
    const copy = new El(this.tag, { ...this.attrs, id: this.id });
    copy.textContent = this.textContent;
    copy.hidden = this.hidden;
    copy.value = this.value;
    copy.checked = this.checked;
    if (deep) { this.children.forEach((child) => copy.appendChild(child.cloneNode(true))); }

    return copy;
  }
  /** The class list as one string — how a module dresses an element it just created. */
  get className() { return this.attrs.class || ''; }
  set className(value) { this.attrs.class = String(value); }
  getAttribute(name) { return name === 'id' ? (this.id || null) : (this.attrs[name] === undefined ? null : String(this.attrs[name])); }
  setAttribute(name, value) { if (name === 'id') { this.id = String(value); } else { this.attrs[name] = String(value); } }
  removeAttribute(name) { delete this.attrs[name]; }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  /** Fire a listener the way a click does — the harness's stand-in for a user acting on the page. */
  fire(type, event = {}) { (this.listeners[type] || []).forEach((fn) => fn({ target: this, currentTarget: this, ...event })); }
  appendChild(child) {
    // A fragment appends its CHILDREN and is emptied, the way the DOM does it — so a thread that was
    // handed `template.content.cloneNode(true)` ends up holding the message, not a wrapper around it.
    if (child.tag === '#fragment') {
      child.children.slice().forEach((each) => this.appendChild(each));
      child.children = [];

      return child;
    }
    // Appending a node that already has a parent MOVES it — which is exactly what dropping a work-board
    // card on another column does. A harness that copied instead of moving would show the card twice.
    if (child.parent) { child.parent.removeChild(child); }
    child.parent = this;
    this.children.push(child);

    return child;
  }
  insertBefore(child, before) {
    if (child.parent) { child.parent.removeChild(child); }
    child.parent = this;
    const at = before ? this.children.indexOf(before) : -1;
    if (at < 0) { this.children.push(child); } else { this.children.splice(at, 0, child); }

    return child;
  }
  removeChild(child) { this.children = this.children.filter((c) => c !== child); child.parent = null; return child; }
  /** Take this node out of the document — what a confirm box does when it is cancelled or answered. */
  remove() { if (this.parent) { this.parent.removeChild(this); } return this; }
  get firstChild() { return this.children[0] || null; }
  get parentNode() { return this.parent; }
  /** Only the shape the modules write: a flat run of `<span class="…"></span>`. */
  set innerHTML(html) {
    this._html = String(html);
    this.children = [];
    for (const m of html.matchAll(/<(\w+)\s+class="([^"]*)"\s*>/g)) { this.appendChild(new El(m[1], { class: m[2] })); }
  }
  /** What was written, verbatim — the markdown a message rendered is asserted against this. */
  get innerHTML() { return this._html || ''; }
  get descendants() { return this.children.flatMap((c) => [c, ...c.descendants]); }
  querySelector(selector) { return this.descendants.find((el) => matches(el, selector)) || null; }
  querySelectorAll(selector) { return this.descendants.filter((el) => matches(el, selector)); }
}

/**
 * A page: a document tree, the framework runtime, the shared guard, and whichever component modules the
 * test names — loaded in the order `LiveBoot::html()` emits them.
 */
export function page({ elements = {}, tree = null, catalog = CATALOG, signals = SIGNALS, modules = [], withGuard = true, bus = false } = {}) {
  const documentEl = tree || new El('html');
  const byId = { ...elements };
  const listeners = {};
  const warnings = [];
  const assigned = [];
  const storage = {};
  let reloads = 0;

  const catalogEl = new El('script', { id: 'milpa-desktop-i18n' });
  catalogEl.textContent = JSON.stringify(catalog);
  byId['milpa-desktop-i18n'] = catalogEl;
  const seedEl = new El('script', { id: 'milpa-live-signals' });
  seedEl.textContent = JSON.stringify(signals);
  byId['milpa-live-signals'] = seedEl;

  const sandbox = {
    console: { warn: (m) => warnings.push(m), log() {}, error() {} },
    location: {
      pathname: '/desktop',
      search: '',
      href: '/desktop',
      assign: (url) => assigned.push(url),
      reload: () => { reloads += 1; },
    },
    localStorage: {
      getItem: (k) => (k in storage ? storage[k] : null),
      setItem: (k, v) => { storage[k] = String(v); },
      removeItem: (k) => { delete storage[k]; },
    },
    setTimeout: (fn, ms) => setTimeout(fn, ms),
    clearTimeout,
    JSON,
    Promise,
    Error,
    encodeURIComponent,
    document: {
      documentElement: documentEl,
      addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
      getElementById: (id) => byId[id] || documentEl.querySelector('#' + id) || null,
      querySelector: (selector) => documentEl.querySelector(selector),
      querySelectorAll: (selector) => documentEl.querySelectorAll(selector),
      createElement: (tag) => new El(tag),
    },
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);

  // The bus is the page's own inline runtime, which the browser runs BEFORE any deferred module — so a
  // module that subscribes at load (the conversation, the turn) finds it, exactly as it will in a browser.
  if (bus) { sandbox.MilpaShell = busStub(); }

  const load = (file) => vm.runInContext(readFileSync(file, 'utf8'), sandbox, { filename: file });
  load(LOCAL_RUNTIME);
  if (withGuard) { load(moduleFile('desktop-guard')); }
  modules.forEach((component) => load(moduleFile(component)));

  // Start Alpine the documented way: the stub appears, then `alpine:init` fires and the runtime hands it
  // the store, the computed signals and every registered factory in registration order.
  const registered = {};
  const stores = {};
  sandbox.Alpine = {
    data(name, factory) { registered[name] = factory; },
    store(name, value) { if (value !== undefined) { stores[name] = value; } return stores[name]; },
    effect() {},
  };
  (listeners['alpine:init'] || []).forEach((fn) => fn());

  /** A component instance as Alpine would build it: the factory's object, with `$store` and `$root`. */
  const mount = (name, config, rootEl = documentEl) => {
    const instance = registered[name](config);
    instance.$store = { milpa: sandbox.MilpaLive.signals() };
    instance.$root = rootEl;
    if (typeof instance.init === 'function') { instance.init(); }

    return instance;
  };

  return {
    sandbox,
    document: sandbox.document,
    documentEl,
    byId,
    listeners,
    warnings,
    assigned,
    storage,
    registered,
    mount,
    load,
    reloads: () => reloads,
    /** The shell bus this page was built with, when it was asked for one. */
    bus: () => sandbox.MilpaShell,
    /** Read a shared signal (one argument) or set it (two) — the runtime's own API, as a page would. */
    signal: (key, ...value) => (value.length === 0 ? sandbox.MilpaLive.signal(key) : sandbox.MilpaLive.signal(key, value[0])),
    desktop: () => sandbox.MilpaLive.desktop,
  };
}

/**
 * A fetch Response as the modules read one: `ok`, `status`, and the body both ways — `text()` for the
 * guard (which must survive a door answering HTML) and `json()` for a caller that knows it asked an op.
 */
export function response(status, body = {}) {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(JSON.stringify(body)),
    json: () => Promise.resolve(body),
  };
}

/** Install a `fetch` that answers with `responses` in order and records every call. */
export function stubFetch(p, responses) {
  const calls = [];
  const queue = [...responses];
  p.sandbox.fetch = (url, init) => {
    calls.push({ url, init });
    const next = queue.shift();

    return next instanceof Error ? Promise.reject(next) : Promise.resolve(next);
  };

  return calls;
}

/** Let the promise chains the modules build settle before asserting. */
export const settle = () => new Promise((resolve) => setTimeout(resolve, 5));
