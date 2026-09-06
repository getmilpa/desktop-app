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
- `GET /desktop?embed=1` — the same page in **embed mode**: the chrome folds and the shell fits one region
  of a host page (the admin's Agent section, below). Same route, same door.
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

## The Agent inside the admin

With [`milpa/admin`](https://github.com/getmilpa/admin) installed, the Desktop becomes the admin's **guest**
(greenhouse `decisions/0209` put both behind one door; `decisions/0210` puts the Desktop inside the panel): the
admin's sidebar lists an **Agent** section, and opening it shows the Desktop shell — the conversation, the
composer, the consent gate — as **one region inside the admin's main**, behind the same door. Nothing to
configure: the admin discovers the section by `instanceof` over the booted plugins, and the Desktop names no
dependency on the admin.

How it holds together:

- **Embed mode.** `GET /desktop?embed=1` serves the *same* shell page with `data-embed="1"` on the root element
  and the chrome folded by CSS — the window strip, the sidebar, the topbar and the status bar are not visible;
  the DOM is kept, so the shell script's contract does not change. The sessions stay reachable through a compact
  **session strip** above the conversation — a Milpa Component like every shell surface (`desktop-session-strip`,
  signed, with `desktop.session_strip.before_render` / `after_render`): the current goal, a `<select>` of every
  session, «New session» wired to the same handler as the sidebar's button; links that would open a chrome
  screen inside the host (Settings, Decisions, Capabilities, Skills, Preview) are not rendered. A gate that
  sends the browser to sign in carries `next=/desktop?embed=1` — the request target — so the round trip lands
  back in embed mode.
- **The section.** `DesktopAppPlugin` implements the admin's `AdminSectionProvider` and declares one
  `AdminSection`: id `agent`, title «Agent» (`Agente` under `desktop.locale: es`), order 10, group `agent`, with
  its own Milpa Component (`desktop-agent`: `Milpa\DesktopApp\Admin\AgentGuestComponent`) and renderer
  (`AgentGuestRenderer`). The renderer emits the region only — the host puts the header (and, the day it paints
  it, the attribution; see below): a same-origin `<iframe src="/desktop?embed=1">` filling the main's available
  height (computed from the admin's own tokens — topbar height and main padding — with fallbacks), plus a guest
  bar with the `gate: <label>` chip and «Open the Desktop» (a new tab). When the Desktop stands behind the
  **passkey** gate and the admin authenticated nobody, no frame is mounted: the region says «Sign in to open the
  Agent» and links the sign-in door with `next` pointing back at `/milpa/admin/s/agent` (the admin's mount point,
  read from the component context; a `?lang=` the page carried travels with it). Whatever the Desktop *answers*
  — a 403, a 500, its sign-in door — loads inside the frame and stays there, the admin whole around it; only
  when the frame gets **no document** from the Desktop (the app is down or unreachable, so the browser paints its
  own error page) does an inline `onload` probe swap in «The Agent did not answer» inside the region — nothing
  outside it moves. (`onerror` is kept too, but browsers report a failed frame navigation as a load of their
  error page, never as an error.)
- **No hard dependency.** The plugin class carries the admin's interface through
  `Milpa\DesktopApp\Admin\AdminGuest`, an interface declared in one of two shapes when it is first loaded: it
  *extends* `Milpa\Admin\Section\AdminSectionProvider` when that interface exists (milpa/admin installed), and
  stands alone with the same one method otherwise. A fresh app **without** milpa/admin boots and serves
  `/desktop?embed=1` — measured in a process where every `Milpa\Admin\*` name is unloadable
  (`tests/Admin/AdminAbsentBootTest.php`). `milpa/admin` is a *dev* dependency here only so the suite boots the
  real panel next to the Desktop (`tests/Admin/AdminGuestTest.php`); it stays a suggestion for an app.

**What the host does for a guest** — milpa/admin **0.11.0** closed the three gaps the first version of this
guest measured against 0.10.1 (greenhouse `decisions/0210`), and the integration test now asserts them
(`tests/Admin/AdminGuestTest.php`):

- **The context carries the principal.** The `ComponentContext` every section receives names who signed in
  (`passkey:<id>`) or `null`; the region and the topbar agree, and behind the passkey gate a signed-in human sees
  the frame, not the door.
- **The host paints the attribution and the groups.** The section header says «declared by DesktopAppPlugin»;
  the sidebar lists the Agent under the **AGENT** group heading, with its glyph `◈`; the Desktop's order (60) sits
  after the admin's own sections, so the panel opens on Plugins, never on the guest.
- **The rule reads the principal the host hands over.** With the runtime's identity chain in place
  (`milpa/app-runtime` ≥ 0.120 and the skeleton of `milpa/framework` ≥ 0.41), the passkey session is a principal on
  every route of the house — the admin's included, whatever its own gate — so an admin on the default loopback gate
  still hands a signed-in principal to the region. Without that chain, put both behind the same gate.
- **The title is resolved once, in the declared locale.** The admin resolves a guest's `title` only through its
  own catalog keys, which a guest cannot extend, so the sidebar item reads «Agent» (or «Agente» under
  `desktop.locale: es`) whatever `?lang=` says — the region itself follows `?lang=`.

**Upgrading.** New in this version: embed mode (`?embed=1`, the same route and door — a `desktop.middleware`
you declared applies to it unchanged), the `chrome` prop on the `desktop-sidebar` component (default `true`;
`false` renders no link to a chrome screen), the `desktop-session-strip` component (rendered in embed mode only;
`Milpa\DesktopApp\Live\SessionStrip` is registered in the container like the other shell surfaces), the Agent
section the admin discovers the moment `milpa/admin` is installed (nothing to declare; remove the admin to
remove the section), and `milpa/admin` as an optional peer — suggested, never required. Nothing you configured
for `0.47` changes meaning.

## Declared views — one runtime per page

Every shell surface is a **declared view** (greenhouse `decisions/0211`): a `desktop-*` component whose
markup comes from a renderer, whose CSS lives in its own file and whose behaviour lives in its own client
module. The page **composes** them and emits **one** runtime.

- **One registry.** `Milpa\DesktopApp\Live\DesktopComponents` holds every component definition *and* its
  renderer (`ComponentRendererRegistry::registerFor`), plus the composer's two form primitives. The shell
  composes the page through an `XhtmlComponentCompiler` over that registry — `<milpa-desktop-sidebar/>`
  resolves to the definition and the renderer — and `POST /desktop/live` is built over the **same** registry
  and the same renderer registry, so an interaction re-renders through the renderer that painted the
  surface and a cross-component `RenderEffect` resolves across the whole Desktop. The registry is shared by
  reference: a component declared after the endpoint was built is still resolved by it.
- **Per-component assets.** Each renderer implements `DeclaresClientAssets` and declares **exactly its own**
  files, served by one route family: `GET /desktop/assets/c/<component>.css` and
  `…/<component>.js`, from `resources/components/<component>/<component>.<ext>`. Like the design-system
  stylesheets these are package files and carry **no gate** — a JSON 401 to a `<link>` breaks the page in
  silence. A name no renderer declares is a 404, never a guess at a path.
- **One emitter.** The page hand-writes **no** runtime `<script>` tag. `LiveBoot::html()` emits, in this
  order and each URL once: every declared **stylesheet**, the **boot** payload, `milpa-live.js`,
  `milpa-live-remote.js`, every declared **component module** (`desktop-guard.js` first), and **Alpine**
  last — each `defer`. The three seeds the local runtime reads (`milpa-live-signals`, `milpa-live-persist`,
  `milpa-live-computed`) are emitted once, next to the boot, by the same helper.
- **The live session comes from the boot.** `LiveBoot::issue()` mints the page session and its CSRF token;
  the runtime echoes `sessionId` in every request body and `LiveController` reads it from there. The
  `milpa_live_sid` cookie is **gone** — a cookie another page set is not this page's session.
- **The signing key.** `desktop.live.signing_secret` / `desktop.live.csrf_secret` still win when declared;
  the **default is now the house's own `live.secret`**, so an envelope a Desktop surface signed is not, to
  another milpa/live endpoint in the same app, a tampered one. Only when the house declares neither does it
  fall back to a stable value derived from the package's path — a per-install default, not a secret.
- **The shared module.** `desktop-guard.js` is a runtime module, not a component: it hangs off
  `MilpaLive.desktop` and owns the Desktop's copy (`tr`), the fetch discipline every call passes through
  (`guarded`: 401 → sign in and come back with `next`; 403 → told once; anything else → rejected with its
  status — and `guardedFlow`, which lets the capabilities gate's `428` through as the flow it is), the
  `desktop.notice` signal, and the **one** document-level click listener behind `onDismiss`.
- **Signals, not couplings.** `session.working` (the send button's glyph/label/disabled and the topbar
  badge's modifiers **bind** to it), `composer.draft`, `ui.dismiss` (the mode menu and the command popup
  consume it), `desktop.notice` (the guard says what happened; the conversation renders it), `composer.panel`
  (which floating panel is open), `ui.theme` (the shell's theme, in three places at once), `desktop.auth.open`
  (the entry overlay's visibility) and `settings.saved` (`{ok, text}` while a save is being reported). Clearing
  the composer is an interaction with the field's own `milpaField` data (`reset('')` / `change(text)`), never a
  synthetic `input` event.

### The declared components, and what each module registers

Every module is one file, registered through `MilpaLive.register(name, factory)` — the runtime **throws** if
a name is bound twice, so no plugin can shadow another's factory or a built-in.

| Component | Markup | CSS | Module → factory | What the factory owns |
|---|---|---|---|---|
| `desktop-sidebar` | `Live\Sidebar` | ✓ | `desktop-sidebar.js` → `desktopSidebar` | `go()` (nav signal + view swap), `isCurrent()`, `newSession()`, `enroll()`; wires the chrome search and the embed strip's controls |
| `desktop-topbar` | `Live\Topbar` | ✓ | `desktop-topbar.js` → `desktopTopbar` | `working` (the badge's binding); owns the shell **theme** (`ui.theme`, restored at load, published as `MilpaLive.desktop.theme`) and the chrome's toggle |
| `desktop-tabs` | `Live\Tabs` | ✓ | `desktop-tabs.js` → `desktopTabs` | `select()` / `isActive()`; publishes `MilpaLive.desktop.showTab()` |
| `desktop-gate` | `Live\Gate` | ✓ | `desktop-gate.js` → `desktopGate` | fills the card from the live `gate.opened` fact (as component **data** the card binds), opens `desktop.gate.open`, `dismiss()` |
| `desktop-activity` | `Live\Activity` | ✓ | `desktop-activity.js` → `desktopActivity` | `record()` — every fact of the `MilpaShell` bus, prepended to the stream |
| `desktop-settings` | `Live\SettingsScreen` | ✓ | `desktop-settings.js` → `desktopSettings` | `save()` (guarded POST; «Saved» only on 2xx, through `settings.saved`), `discard()`, `setTheme()` / `isTheme()` |
| `desktop-auth` | `Live\AuthOverlay` | ✓ | `desktop-auth.js` → `desktopAuth` | `enter()` (guarded `POST /desktop/sessions`, reload only on 2xx), `dismiss()`; publishes `MilpaLive.desktop.auth` |
| `desktop-session-strip` | `Live\SessionStrip` | ✓ | — | its controls call the **sidebar's** module: one ceremony, no copy |
| `desktop-conversation`, `desktop-user-message`, `desktop-agent-message`, `desktop-thinking`, `desktop-tool-call`, `desktop-task`, `desktop-system-notice`, `desktop-result-claim` | `Live\*` | ✓ | — | still driven by the shell's inline script (the conversation/composer group is a later slice) |
| `desktop-work-board`, `desktop-context` | `Live\*` | — | — | behaviour **and** look still in the shell (the board's drag-drop posts to `/desktop/work` from the inline script, and it paints with inline `style=`) — a later slice |

The client modules are measured by execution: `npm test` (or `node --test 'tests/js/**/*.test.mjs'`,
Node ≥ 22, nothing to install) loads the shipped files **verbatim** into a stub page, hands each factory the
`$store` and `$root` Alpine would, and runs it — the tab switch, the gate fill, the guarded save, the theme,
the search filter, the session ceremony and the activity stream are all asserted by running them.

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

**Upgrading (0.49.0).** Declared views land as described above; nothing you *configured* changes meaning,
but eight things change shape:

1. **One runtime through `LiveBoot`.** The page no longer carries hand-written `<script src>` tags for
   `milpa-live.js`, `milpa-live-remote.js` or `alpine.min.js`, nor hand-written boot/signals/persist/computed
   tags in the body — `LiveBoot::html()` emits the whole block (deferred) at the end of `<head>`, with the
   seeds beside it. A plugin that pinned those literal tags pins the new block instead.
2. **Per-component assets.** New route family `GET /desktop/assets/c/{file}` (gate-free, cached one hour —
   the URL carries no version and the file changes with the release, so an immutable year would leave a
   browser running last release's module against this release's markup),
   and the CSS of every declared surface moved out of the shell's inline `<style>` — and out of the `style="…"`
   attributes its renderers carried — into `resources/components/<name>/<name>.css`. The look is unchanged; a
   deployment that proxies `/desktop/assets/*` must let the new segment through, and a plugin that styled a
   surface by overriding an inline `style` attribute now overrides a class instead.
3. **The live session id comes from the boot, not a cookie.** `POST /desktop/live` reads `sessionId` from
   the request body — where the client runtime has always sent it. The shell **no longer sets**
   `milpa_live_sid`, and the endpoint no longer accepts it: a host that built its own adapter around that
   cookie must send the boot's id in the body. `ComposerField::SESSION_COOKIE` is kept as a name, unused.
4. **Signals replaced four couplings.** `desktop.notice` (the guard emits, the conversation renders),
   `session.working` + `composer.draft` (the send button and the topbar badge bind instead of being poked),
   `ui.dismiss` (one document listener, two consumers), and the composer's reset through `milpaField`
   instead of a synthetic `input` event. A plugin that dispatched an `input` event at
   `#milpa-composer-dock textarea` to clear the field should call the component's `reset('')` instead.
5. **Two screens became components.** The Settings screen and the entry overlay («Open workspace») were raw
   HTML in the shell's template; they are now `desktop-settings` and `desktop-auth`, with renderers
   (`Live\SettingsScreen`, `Live\AuthOverlay`), their own CSS and modules, and `before_render` /
   `after_render` events like every other surface. Their element ids (`milpa-auth`, `set-end`, `set-mode`,
   `milpa-save-settings`, `milpa-settings-saved`, …) are unchanged, so a plugin that reached for one still
   finds it; a plugin that wants to *change* them should subscribe to `desktop.settings.after_render` /
   `desktop.auth.after_render` rather than patching the page. Their copy is **catalog copy** now
   (`src/I18n/Catalog.php`, `settings.*` and `auth.*` in en/es), so a `desktop.locale = es` app no longer
   reads two English screens inside a Spanish shell. The overlay declares **no wire action**: its
   visibility is the `desktop.auth.open` signal, and `Escape` dismisses it.
6. **Behaviour moved out of the page's inline script.** The tab switch, the theme, the sidebar's navigation
   and search, «New session», the passkey probe, the Activity stream and the consent gate are no longer in
   the shell's `<script>` — each is in its component's module. Anything that pinned those literal functions
   (`showTab`, `applyTheme`, `showView`, `openNewSession`) now finds them in
   `/desktop/assets/c/<component>.js`. The behaviour a user sees is the same.
7. **A gate bug fixed by execution.** The page's `gate.opened` handler ended by unhiding an element with the
   id `milpa-decisions-badge` — which nothing in this package renders. Every parked question therefore threw
   a `TypeError` right after filling the card, and the gate's Dismiss listener threw on every click. The
   decisions count is the sidebar's badge and `MilpaShell.addDecision()` ticks it; the gate no longer reaches
   for it. If you rendered a `#milpa-decisions-badge` yourself to silence the error, you can drop it.
8. **Four more signals.** `composer.panel` (the chips set it, both floating panels bind `:hidden` to it),
   `ui.theme` (`system | dark | light` — the chrome toggle and the Settings buttons set the same value),
   `desktop.auth.open` and `settings.saved`. Anything that poked those elements directly should set the
   signal instead.

Also: `desktop.live.*_secret` now falls back to the house's `live.secret` before the per-install default.
Declare `live.secret` and every milpa/live endpoint in the app verifies with one key.

## The arc

The Desktop is a Milpa plugin: the app hosts its own dashboard, built from Milpa components, at a real
origin — so the passkey ceremony is same-origin and every panel is a reactive component
(`decisions/0188`). *Everything is built from Milpa components.*

## License

Apache-2.0 · © Rodrigo Vicente - TeamX Agency

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=desktop-app)**.
