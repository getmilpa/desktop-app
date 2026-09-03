<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# milpa/desktop-app

**A Milpa app hosts itself as a desktop app.**

The Milpa Desktop is not an Electron app that *drives* a separate Milpa — it is a Milpa that gains
desktop hands by installing this plugin. The backend lives in the **same** app. Installing the plugin
mounts a shell route; an Electron (or plain browser) host then loads that URL at a **real origin**
(`http://localhost:<port>/desktop`) instead of a `file://` renderer.

That single move dissolves the constraint that blocked the passkey ceremony: WebAuthn refuses a
`file://` origin and an IP is not a valid relying-party id — but the served shell shares its origin
with the app's own `/webauthn/*` doors, its live components and its consent gates. **One channel, one
origin** (greenhouse `decisions/0188`).

## Install

```bash
composer require milpa/desktop-app
```

Then declare it in `config/plugins.php`. Installing the plugin *is* the activation; a Milpa without it
simply has no desktop shell.

## What it serves

- `GET /desktop` — the Milpa Desktop dashboard, served over HTTP. Point an Electron `loadURL` (or a
  browser) at it. Built-in panels: the consent gate, the activity stream, and the passkey doors.
- `GET /desktop/events` — the shell's live event feed (SSE), the transport when no hub is wired.

## Add a dashboard panel (the DX)

Every panel is a Milpa component: server-rendered, then reactive on the client. A plugin adds one by
subscribing to the compose event and calling `addPanel()`, then driving it live from the client runtime.

```php
// In your plugin's boot():
$events->subscribe(ShellController::COMPOSE_EVENT, [$this, 'onCompose']);

public function onCompose(string $eventName, array $payload): void
{
    $payload['composition']->addPanel('sessions', 'Sessions', <<<'H'
      <p class="mono"><span data-count>0</span> active</p>
      <script>
        MilpaShell.on('session.count', function (d) {
          MilpaShell.panel('sessions').querySelector('[data-count]').textContent = d.count;
        });
      </script>
    H);
}
```

The client runtime `MilpaShell`: `on(type, cb)` / `onAny(cb)` react to events, `panel(id)` returns your
panel's body element, `onStatus(cb)` tracks the live connection. Events reach the browser through a Mercure
hub when one is configured (`desktop.mercure.*`), else through the `/desktop/events` feed.

## Live updates over a Mercure hub

Configure `desktop.mercure.{hub_url,public_url,publisher_key,subscriber_key}` and the app publishes shell
changes to the hub (via `milpa/mercure`) while the dashboard subscribes to it — the grid updates with no
poll. Without a hub, the app runs on the shared-log feed.

## The arc

The Desktop is a Milpa plugin: the app hosts its own dashboard, built from Milpa components, at a real
origin — so the passkey ceremony is same-origin and every panel is a reactive component
(`decisions/0188`). *Everything is built from Milpa components.*

## License

Apache-2.0 · © Rodrigo Vicente - TeamX Agency

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=desktop-app)**.
