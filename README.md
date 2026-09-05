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
php -S 127.0.0.1:8080 -t public public/router.php  # 4. serve over HTTP
# 5. open http://localhost:8080/desktop
```

The origin is `localhost`, not `127.0.0.1`: WebAuthn accepts `localhost` as a relying-party id and refuses an
IP, so a passkey gate in front of the Desktop (below) only matches an origin spelled that way. The server is
still *bound* on the IP: PHP's built-in server listens on one address family, and `php -S localhost:8080` lands
on `[::1]` alone wherever the name resolves to IPv6 first, refusing every IPv4 client — a browser opening
`localhost` reaches a `127.0.0.1` bind with family fallback, and the passkey `rpId` matches the name either way.

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

Every one of those routes — and the data, export, live and write endpoints — stands behind the door below.
Only the assets under `/desktop/assets/*` are public.

## Behind the door

The Desktop stands behind the same door as the admin (greenhouse `decisions/0209`). The plugin attaches the
PSR-15 middleware the app declares under **`desktop.middleware`** to every shell route; **since this version the
default answers only to loopback** (`Milpa\DesktopApp\Http\LoopbackOnlyMiddleware`): a request from the LAN gets
`403` — a small page for a browser, `{"ok":false,"error":"loopback_only"}` for the shell's own calls. Only a
literally empty list `[]` opens the Desktop. Anything misdeclared — a non-string entry, an associative map, a
value that is not a list, a class that does not exist or is not a PSR-15 middleware — makes the **whole** stack
fall to loopback-only, never open and never the half that loads; the topbar chip says `gate: fallback` in warning.
`desktop.locale` (`en` default, `es`) chooses the language of the chips and the notices.

To put it behind a passkey, name `milpa/app-runtime`'s gate — the Desktop does not import it; it names the class:

```php
// config/app.php
'desktop' => ['middleware' => [Milpa\AppRuntime\Web\PasskeyGateMiddleware::class]],
```

`PasskeyPlugin` must be declared in `config/plugins.php`, `'passkey' => ['rpId' => 'localhost']` set in
`config/app.php`, and the key enrolled with the scope the gate checks plus the one the turns need:

```bash
php bin/coa identity:enroll --fingerprint=<credential id> --scopes=milpa.admin --scopes=agent:run --sign
```

From then on identity replaces the address. A browser loading `/desktop` without a session is sent to
`/webauthn/signin?next=/desktop`; every `fetch()` the shell makes passes through one guard, so a gated call that
answers `401 {signin}` sends the browser to sign in and back (`next` carries the path and query), a `403` is told as
a system notice, and «Saved» is only ever said on a `2xx`. The topbar shows `signed in as <actor id>` — read from
the `milpa.auth` attribute the gate leaves on the request, never from a cookie — and `gate: passkey` when that class
is the whole stack (`loopback · custom · open · fallback` otherwise). The assets are exempt: a JSON `401` to a
`<link>` or `<script>` would break the page silently.

**Upgrading.** Before this version the Desktop had no gate: every route was open to whoever could reach the port.
Now the default is loopback-only, so a house that serves the Desktop on the LAN must declare its gate explicitly —
a passkey gate as above, its own PSR-15 stack, or `[]` to keep it open on purpose. Naming the passkey gate without
`PasskeyPlugin` declared and an `rpId` set is a fail-closed `500` at the first gated request, as it is for the
admin: the router refuses to skip a middleware it cannot resolve.

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

## Commands

The composer understands slash commands (greenhouse `decisions/0202`). `/goal <text>` sets the session's
standing goal mid-session, `/goal clear` drops it and `/goal` alone shows it; `/mode ask|acknowledge|auto`
chooses the autonomy mode, which **applies from the next turn** (every turn carries the chosen mode to the
agent — there is one writer, the turn itself); `/help` lists what the composer understands; and
`/<skill-name> [args]` invokes a **user-invocable** skill and starts a turn with its instructions. Typing `/`
opens a completion list the house serves — its own commands plus every user-invocable skill. Only a real
command is intercepted: a prompt that merely starts with a slash (`/tmp/app.log has errors`) reaches the model
unchanged.

Each command is a governed operation of the house — the same operation the CLI, the TUI and the MCP surface
run (`cli`/`tui`/`mcp`/`http`); the Desktop invents no action, it only calls the operation's HTTP projection
(`POST /agent/goal`, `GET /skill/invoke`) and reports the operation's own answer. `agent:goal`, `agent:mode`
and `skill:invoke` are deliberately **off the model's table**: they spend the human's authority, so only a
human surface fires them. `/goal` and `/<skill>` need `milpa/app-runtime` ≥ 0.116 (the release carrying
`agent:goal` and `skill:invoke`) and the app exposing those operations in `config/http.php`; when one is not
exposed, the composer says so instead of failing silently. `milpa/app-runtime` is not a dependency of this
plugin — the Desktop's coupling to the agent is soft by design — so a Desktop without it simply has no `/goal`.

A goal only bounds what the automatic mode may already pre-consent — it never pre-approves a signature
(`requiresConfirmation`, the Executable+Privileged ceiling) or third-party egress.

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
