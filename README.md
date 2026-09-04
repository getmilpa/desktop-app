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

## Run it end to end

From a fresh Milpa app to the shell in a browser — the whole path, proven on a fresh app
(greenhouse `evidence/0487`):

```bash
composer create-project milpa/framework my-app   # 1. a Milpa app
cd my-app
composer require milpa/desktop-app                # 2. add the plugin
# 3. declare Milpa\DesktopApp\DesktopAppPlugin::class in config/plugins.php
php -S 127.0.0.1:8080 -t public public/router.php # 4. serve over HTTP
# 5. open http://127.0.0.1:8080/desktop
```

Step 4 needs a `public/router.php` so the built-in server hands non-file requests (the shell, and the
`/desktop/assets/*.css` served by a route, not from disk) to the Kernel:

```php
<?php // public/router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // serve a real static file as-is
}
require __DIR__ . '/index.php'; // everything else goes to the Kernel
```

A real deployment (nginx/Caddy/Apache) needs no router — this is only the built-in server's convention.

## A native window (Electron)

`examples/electron/` is a minimal Electron host: it starts the app's `php -S` and loads `/desktop` in a
native window at a real origin — the Desktop is a Milpa serving itself, not an Electron app driving one.

```bash
cd examples/electron && npm install
MILPA_APP_DIR=/path/to/my-app npm start
```

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
poll. Without a hub, the app runs on the shared-log feed. One more key, `desktop.mercure.cors_origin`, is
OPTIONAL and declaration-only — read only by the service declaration below, not by the wiring: the origin(s)
the hub lets subscribe, space-separated when there are several.

The plugin also DECLARES the hub it needs: `DesktopAppPlugin` implements the runtime's
`StackProviderInterface` (`Milpa\Runtime\Stack`, greenhouse decisions/0201) and returns one
`ServiceDeclaration` — `dunglas/mercure`, container port 80 published on the port of the URL the browser
reaches (`public_url`, else `hub_url`, and only when that URL's host is loopback — an in-network
`http://mercure:80/...` names no host port; 3000 otherwise), `SERVER_NAME=:80`, the publisher/subscriber
JWT keys as SECRETS that reference `desktop.mercure.publisher_key` / `subscriber_key` (never shown,
projected as `${NAME}`), and `MERCURE_EXTRA_DIRECTIVES` with `cors_origins` (`desktop.mercure.cors_origin`
verbatim, default `http://127.0.0.1:8080 http://localhost:8080` — both spellings of the quickstart origin,
because a credentialed EventSource is refused unless the browser's origin matches exactly) plus
`anonymous`. An admin panel can list the service, probe its port and project a compose fragment from that
declaration; nothing in this plugin starts a container. The declaration reads the wiring's keys plus that
one optional key.

## The arc

The Desktop is a Milpa plugin: the app hosts its own dashboard, built from Milpa components, at a real
origin — so the passkey ceremony is same-origin and every panel is a reactive component
(`decisions/0188`). *Everything is built from Milpa components.*

## License

Apache-2.0 · © Rodrigo Vicente - TeamX Agency

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=desktop-app)**.
