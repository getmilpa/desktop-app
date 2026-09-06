/*!
 * desktop-agent-message — the agent's answer, as a client module (greenhouse decisions/0211, phase C1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the agent-message prototype's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers NO Alpine factory — the prototype is cloned per answer and a
 * per-instance `x-data` double-initialises (greenhouse decisions/0191) — so it registers its KIND in
 * `MilpaLive.desktop.messages.agent` and the conversation routes to it.
 *
 * This message type has behaviour of its own, three ways:
 *
 *   - `fill()` renders the answer as MARKDOWN. `renderMarkdown()` is the safe subset and it is this
 *     component's, not the thread's: it ESCAPES the model's text first — the model's output is never
 *     injected as raw HTML — then applies a small, known-safe grammar (fenced and inline code, bold,
 *     italic, links with a safe scheme, headings, lists). Every tag it emits is one it wrote.
 *   - `click()` owns the answer's foot tools: Copy (to the clipboard, with a flash of feedback) and
 *     Regenerate (the turn re-runs the same prompt — asked of `MilpaLive.desktop.turn`, never re-derived).
 *   - `verdict()` stamps the ledger's judgement onto the LAST answer's tool row, which is where Rod asked
 *     it to ride. The words come from the conversation (one owner for the judgement's copy); this
 *     component only knows where its own row is.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live) {
    if (window.console && console.warn) { console.warn('[desktop-agent-message] the milpa/live runtime must load first'); }

    return;
  }

  /** The prototype the conversation clones per answer. */
  var PROTO_ID = 'milpa-agent-msg-proto';
  /** How long the Copy tool shows that it copied. */
  var COPIED_MS = 1200;

  function desk() { return live.desktop || null; }
  function conversation() { var d = desk(); return d ? (d.conversation || null) : null; }

  function messages() {
    var d = desk();
    if (!d) { return null; }
    d.messages = d.messages || {};

    return d.messages;
  }

  var registry = messages();
  if (!registry) {
    if (window.console && console.warn) { console.warn('[desktop-agent-message] the shared desktop runtime must load first'); }

    return;
  }
  if (registry.agent) {
    if (window.console && console.warn) { console.warn('[desktop-agent-message] loaded twice; ignoring the second copy'); }

    return;
  }

  /**
   * The answer as markdown — a safe subset over ESCAPED text (see the file header).
   *
   * The fenced blocks are lifted out first and put back last, so nothing inside a code block is read as
   * markup; the sentinel is a character no message can carry (U+FFFD, the replacement character).
   */
  function renderMarkdown(source) {
    var src = String(source == null ? '' : source);
    function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    var blocks = [];
    src = src.replace(/```[^\n]*\n?([\s\S]*?)```/g, function (_, code) {
      blocks.push('<pre class="md-pre"><code>' + esc(code.replace(/\n$/, '')) + '</code></pre>');

      return '\uFFFDB' + (blocks.length - 1) + '\uFFFD';
    });
    var out = esc(src);
    out = out.replace(/`([^`\n]+)`/g, '<code class="md-code">$1</code>');
    out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    out = out.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    out = out.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    var lines = out.split('\n');
    var html = '';
    var inList = false;
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var heading = line.match(/^(#{1,3})\s+(.*)$/);
      var item = line.match(/^\s*[-*]\s+(.*)$/);
      if (item) {
        if (!inList) { html += '<ul class="md-ul">'; inList = true; }
        html += '<li>' + item[1] + '</li>';
        continue;
      }
      if (inList) { html += '</ul>'; inList = false; }
      if (heading) {
        var level = heading[1].length + 2;
        html += '<h' + level + ' class="md-h">' + heading[2] + '</h' + level + '>';
        continue;
      }
      if (line.trim() === '') { continue; }
      html += '<p>' + line + '</p>';
    }
    if (inList) { html += '</ul>'; }
    html = html.replace(/<p>\uFFFDB(\d+)\uFFFD<\/p>/g, function (_, at) { return blocks[at]; });
    html = html.replace(/\uFFFDB(\d+)\uFFFD/g, function (_, at) { return blocks[at]; });

    return html;
  }

  registry.agent = {
    proto: PROTO_ID,
    /** The answer's body: markdown the component rendered from escaped text. */
    fill: function (root, opts, at) {
      var body = at(root, '[data-agent-body]');
      if (body) { body.innerHTML = renderMarkdown(opts.text || ''); }
    },
    /** The answer's own markdown renderer, published so a test (or a plugin) reads the same one. */
    markdown: renderMarkdown,
    /** The foot tools: copy the answer, or run the same prompt again. */
    click: function (event) {
      var copy = event.target.closest('[data-agent-copy]');
      if (copy) {
        var message = copy.closest('.msg--agent');
        var body = message ? message.querySelector('[data-agent-body]') : null;
        var text = body ? body.textContent : '';
        if (window.navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
          navigator.clipboard.writeText(text).catch(function () { /* a refused clipboard is not an error to report */ });
        }
        copy.classList.add('is-done');
        setTimeout(function () { copy.classList.remove('is-done'); }, COPIED_MS);

        return true;
      }
      if (event.target.closest('[data-agent-regenerate]')) {
        var d = desk();
        if (d && d.turn && typeof d.turn.regenerate === 'function') { d.turn.regenerate(); }

        return true;
      }

      return false;
    },
    /**
     * Stamp the ledger's verdict onto the last answer's tool row; false when there is no answer to ride.
     */
    verdict: function (thread, ok, reasons) {
      var answers = thread.querySelectorAll('.msg--agent');
      var last = answers.length ? answers[answers.length - 1] : null;
      var slot = last ? last.querySelector('[data-agent-verdict]') : null;
      if (!slot) { return false; }
      var conv = conversation();
      var verified = ok !== false;
      var text = conv ? conv.tip(verified, reasons) : '';
      slot.setAttribute('data-verified', verified ? '1' : '0');
      var mark = slot.querySelector('[data-verdict-mark]');
      if (mark) { mark.textContent = verified ? '✓' : '⚠'; }
      var label = slot.querySelector('[data-verdict-label]');
      if (label && conv) { label.textContent = conv.label(verified); }
      var tip = slot.querySelector('[data-verdict-tip]');
      if (tip) { tip.textContent = text; }
      slot.setAttribute('aria-label', conv ? conv.aria(verified, text) : text);
      slot.hidden = false;

      return true;
    },
  };
})();
