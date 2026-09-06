/*!
 * desktop-activity — the session's live fact stream, as a client module (greenhouse decisions/0211, phase B6).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the Activity tab's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers ONE Alpine factory, `desktopActivity`, bound by the component's root
 * with `x-data="desktopActivity()"`.
 *
 * The tab is a PROJECTION of the shell's event bus: every fact `MilpaShell` publishes — whatever its type,
 * hence `onAny` — is prepended to `#milpa-activity` as one `mui-replay__event` row, newest first, and the
 * server-rendered "no facts recorded yet" placeholder is removed the first time a real one lands. The text
 * is set with `textContent`, never as HTML: a fact carries a payload the Desktop did not write.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-activity] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopActivity')) {
    if (window.console && console.warn) { console.warn('[desktop-activity] loaded twice; ignoring the second copy'); }

    return;
  }

  live.register('desktopActivity', function () {
    return {
      init: function () {
        var self = this;
        if (window.MilpaShell && typeof window.MilpaShell.onAny === 'function') {
          window.MilpaShell.onAny(function (type, data) { self.record(type, data); });
        }
      },
      /** One live fact as a row at the top of the stream — the component's own `<ol>`. */
      record: function (type, data) {
        var stream = document.getElementById('milpa-activity');
        if (!stream) { return null; }

        // The server's empty state goes the moment the first real fact lands. It is found by the MARK the
        // renderer puts on it (`data-activity-empty`), never by reading its words: matching on the English
        // «no facts» made the placeholder outlive its own translation.
        var placeholder = stream.querySelector('[data-activity-empty]');
        if (placeholder && placeholder.parentNode) { placeholder.parentNode.removeChild(placeholder); }

        var row = document.createElement('li');
        row.className = 'mui-replay__event';
        row.innerHTML = '<span class="mui-replay__type"></span> <span class="mui-replay__actor"></span>';
        row.querySelector('.mui-replay__type').textContent = String(type == null ? '' : type);
        row.querySelector('.mui-replay__actor').textContent = JSON.stringify(data) + ' · live';
        stream.insertBefore(row, stream.firstChild);

        return row;
      },
    };
  });
})();
