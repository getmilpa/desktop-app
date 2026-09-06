/*!
 * desktop-conversation — the Desktop's conversation thread, as a client module (greenhouse decisions/0211, phase C1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the conversation's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers ONE Alpine factory, `desktopConversation`, which the `#milpa-chat`
 * section binds with `x-data="desktopConversation()" @click="onClick($event)"`.
 *
 * The thread knows TWO things and no more (greenhouse decisions/0191):
 *
 *   - HOW a message lands: clone the prototype the server rendered for that kind, let the kind's own
 *     component fill the clone's data regions, append it. No `createElement`, no markup written here.
 *   - WHERE the clicks go: one delegated handler asks each message component whether the click was its
 *     own, so a message TYPE added later needs no wiring in the container.
 *
 * WHAT each kind renders is its own component's, registered into `MilpaLive.desktop.messages` by that
 * component's module (`desktop-thinking`, `desktop-agent-message`, `desktop-tool-call`,
 * `desktop-result-claim`). The three plain kinds — the user's message, a task row, a system notice — are
 * registered HERE: their whole fill is one `textContent` into one region and they have no interaction of
 * their own, so a module apiece would be a file with a line in it.
 *
 * The closure VERDICT's words live here too (`tip()` / `label()`): two message shapes show the same
 * judgement — the agent answer's tool row and the standalone result claim — so one owner says it once,
 * from the catalog, and they cannot disagree.
 *
 * The thread is also what CONSUMES `desktop.notice`: the guard says what a door answered and the
 * conversation renders it as a system message. Nothing else couples the guard to the chat.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-conversation] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopConversation')) {
    if (window.console && console.warn) { console.warn('[desktop-conversation] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The thread's element id — the ONE place this module resolves it. */
  var CHAT_ID = 'milpa-chat';

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  /**
   * The registry each message COMPONENT registers its kind into: `{ proto, fill, click, append }`.
   *
   * It is created by whichever module gets there first — the modules are emitted in the order their
   * surfaces were painted, and none of them may assume it is that one.
   */
  function messages() {
    var d = desk();
    if (!d) { return null; }
    d.messages = d.messages || {};

    return d.messages;
  }

  /** The thread element, or null on a page that renders no conversation. */
  function chat() { return document.getElementById(CHAT_ID); }

  /**
   * One data region of a clone: the region is a DESCENDANT of the clone, or the clone's own root.
   *
   * `querySelector` never returns the node it was called on, and the system notice carries
   * `data-system-body` ON the `.msg` root — so a plain descendant lookup found nothing and every guard
   * notice painted an EMPTY bubble. Asking both ways makes a fill independent of where a component (or a
   * plugin that re-rendered it) put its region.
   */
  function region(root, selector) { return root.matches(selector) ? root : root.querySelector(selector); }

  /** The words a verdict is said in — one owner, two message shapes (the answer's row, the result claim). */
  function tip(ok, reasons) {
    return ok !== false
      ? tr('verdict.backed')
      : tr('verdict.disputed.why', (reasons ? String(reasons) : tr('verdict.disputed.default')));
  }

  /** What the verdict's badge says. */
  function label(ok) { return ok !== false ? tr('verdict.verified') : tr('verdict.disputed'); }

  /** The same judgement for a screen reader: the badge, then the reason. */
  function aria(ok, text) { return tr(ok !== false ? 'verdict.aria.verified' : 'verdict.aria.disputed', text); }

  /**
   * Append one message of `kind`: the component that owns the kind fills the clone of its prototype.
   *
   * A kind whose component declares its own `append` (the thinking block streams across a whole turn
   * instead of landing once) takes that path instead. An unknown kind lands as a system notice rather
   * than silently not landing.
   */
  function append(kind, opts) {
    var thread = chat();
    var registry = messages() || {};
    var spec = registry[kind] || registry.system;
    if (!thread || !spec) { return null; }
    opts = opts || {};
    if (typeof spec.append === 'function') { return spec.append(thread, opts); }
    var proto = document.getElementById(spec.proto);
    if (!proto || !('content' in proto)) { return null; }
    var frag = proto.content.cloneNode(true);
    var root = frag.querySelector('.msg');
    if (root && typeof spec.fill === 'function') { spec.fill(root, opts, region); }
    thread.appendChild(frag);
    if (root && typeof root.scrollIntoView === 'function') { root.scrollIntoView({ block: 'end' }); }

    return root;
  }

  /** Stream reasoning into the live thinking block — the thinking component's own lifecycle. */
  function reasoning(text) {
    var spec = (messages() || {}).thinking;

    return (spec && typeof spec.delta === 'function') ? spec.delta(chat(), text) : null;
  }

  /** Close the live thinking block, if one is open. */
  function endReasoning() {
    var spec = (messages() || {}).thinking;

    return (spec && typeof spec.end === 'function') ? spec.end() : null;
  }

  /**
   * The turn's closure verdict, RIDING the last agent answer (Rod's ask — it saves a whole line).
   *
   * False when there is no answer to ride, so the caller falls back to the standalone result claim.
   */
  function verdict(ok, reasons) {
    var spec = (messages() || {}).agent;
    var thread = chat();

    return !!(thread && spec && typeof spec.verdict === 'function' && spec.verdict(thread, ok, reasons) === true);
  }

  /** The delegated click: the first message component that owns the target handles it. */
  function dispatch(event) {
    if (!event || !event.target || typeof event.target.closest !== 'function') { return false; }
    var registry = messages() || {};
    for (var kind in registry) {
      if (Object.prototype.hasOwnProperty.call(registry, kind)
        && typeof registry[kind].click === 'function'
        && registry[kind].click(event) === true) {
        return true;
      }
    }

    return false;
  }

  // The plain kinds: one region, one text, no interaction (see the file header).
  var registry = messages();
  if (registry) {
    registry.user = {
      proto: 'milpa-user-msg-proto',
      fill: function (root, opts, at) { var body = at(root, '[data-user-body]'); if (body) { body.textContent = opts.text || ''; } },
    };
    registry.task = {
      proto: 'milpa-task-msg-proto',
      fill: function (root, opts, at) {
        var title = at(root, '[data-task-title]');
        if (title) { title.textContent = opts.title || ''; }
        var status = at(root, '[data-task-status]');
        if (status) { status.textContent = opts.status || 'todo'; }
      },
    };
    registry.system = {
      proto: 'milpa-system-msg-proto',
      fill: function (root, opts, at) { var body = at(root, '[data-system-body]'); if (body) { body.textContent = opts.text || ''; } },
    };
  }

  /**
   * Subscribe to the stream, ONCE.
   *
   * At module load when the bus is already there (it is: the shell's runtime tag is inline at the top of
   * `<body>` and every module is deferred), and again from the factory's `init()` — because the hub is
   * connected on `DOMContentLoaded`, which is AFTER every deferred module has run: subscribing at load is
   * what keeps a fact already queued at the hub from arriving before there is anyone to render it.
   */
  var subscribed = false;

  function subscribe() {
    var shell = window.MilpaShell;
    if (subscribed || !shell || typeof shell.on !== 'function') { return false; }
    subscribed = true;
    shell.on('agent.reasoning', function (fact) { reasoning((fact && fact.text) || ''); });
    shell.on('agent.message', function (fact) { endReasoning(); append('agent', { text: (fact && fact.text) || '' }); });
    shell.on('agent.thinking', function (fact) { append('thinking', { text: (fact && fact.text) || '' }); });
    shell.on('tool.call', function (fact) { append('tool', { name: (fact && fact.name) || 'tool', result: (fact && fact.result) || '' }); });
    shell.on('task.added', function (fact) { append('task', { title: (fact && fact.title) || '', status: (fact && fact.status) || 'todo' }); });
    shell.on('system.notice', function (fact) { append('system', { text: (fact && fact.text) || '' }); });

    return true;
  }

  /** Consume `desktop.notice`, ONCE: the guard says what happened, the thread renders it. */
  var listening = false;

  function listen() {
    var d = desk();
    if (listening || !d || typeof d.onNotice !== 'function') { return false; }
    listening = true;
    d.onNotice(function (notice) { append('system', { text: (notice && notice.text) || '' }); });

    return true;
  }

  live.register('desktopConversation', function () {
    return {
      /** Late subscriptions, for a page whose bus or guard arrived after this module did. */
      init: function () {
        subscribe();
        listen();
      },
      /** The thread's ONE click handler — every message component's actions ride it. */
      onClick: function (event) {
        dispatch(event);
      },
    };
  });

  if (live.desktop) {
    live.desktop.conversation = {
      append: append,
      chat: chat,
      region: region,
      reasoning: reasoning,
      endReasoning: endReasoning,
      verdict: verdict,
      click: dispatch,
      tip: tip,
      label: label,
      aria: aria,
    };
  }

  subscribe();
  listen();
})();
