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
  `milpa-live-remote.js`, the five modules the **page** declares (`desktop-guard.js`,
  `desktop-shell-bus.js`, `desktop-hub.js`, `desktop-turn.js`, `desktop-commands.js`), every declared
  **component module** in the order its surface was painted, and **Alpine** last — each `defer`. The three
  seeds the local runtime reads (`milpa-live-signals`, `milpa-live-persist`, `milpa-live-computed`) are
  emitted once, next to the boot, by the same helper.
- **No behaviour in the shell's page — none at all.** Every inline `<script>` the shell itself renders is
  DATA: `#milpa-commands` (the command list), `#milpa-desktop-i18n` (the copy), `#milpa-desktop-guard`
  (the app's sign-in path, for a 401 whose body names no door — `{"signin":""}` when this Desktop is not
  behind the passkey gate), `#milpa-desktop-session` (the agent session the turn carries) and
  `#milpa-desktop-hub` (the hub's URL with its two exact topics, `{}` when there is no hub), each a
  `type="application/json"` tag, plus the three runtime seeds, the boot, and one signed
  `application/milpa+xhtml` envelope per painted surface. There is **no executable inline script left in
  what the shell renders**, and `DeclaredViewsTest` fails if one appears. The exception is stated rather
  than hidden: `addSection()` / `addPanel()` interpolate a **plugin's** html verbatim (the DX example
  below does exactly that), so a contributed panel's `<script>` is the plugin's own responsibility and the
  test's claim is about the shell's template, which is what it renders. The one surface this package
  contributes to *another* page — the admin's Agent region — carries none either: its look and its
  frame probe are `desktop-agent-guest.css` / `.js`, declared like every other component's.
- **What the shell's `<style>` still holds.** Only the shell's own frame: the document (`body`, the
  scrollbar rule, reduced motion), the window strip (`.chrome`, `.lights`), the grid the views sit in
  (`.mui-shell`, `.mui-shell__main`, `.view--session*`, `.tabpane*`), the `[hidden] { display: none
  !important }` that makes `hidden` beat a component's own `display`, the embed-mode fold, and one tune of
  the design system's `.mui-empty` (seven different surfaces print one; no single component owns it).
  Everything else moved to the stylesheet of the component that owns it.
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
| `desktop-sidebar` | `Live\Sidebar` | ✓ | `desktop-sidebar.js` → `desktopSidebar` | `go()` (nav signal + view swap), `isCurrent()`, `newSession()`, `enroll()`; wires the chrome search and the embed strip's controls; ticks its own decisions badge on `decision.parked` |
| `desktop-topbar` | `Live\Topbar` | ✓ | `desktop-topbar.js` → `desktopTopbar` | `working` (the badge's binding); owns the shell **theme** (`ui.theme`, restored at load, published as `MilpaLive.desktop.theme`) and the chrome's toggle |
| `desktop-tabs` | `Live\Tabs` | ✓ | `desktop-tabs.js` → `desktopTabs` | `select()` / `isActive()`; publishes `MilpaLive.desktop.showTab()` |
| `desktop-gate` | `Live\Gate` | ✓ | `desktop-gate.js` → `desktopGate` | fills the card from the live `gate.opened` fact (as component **data** the card binds), opens `desktop.gate.open`, `dismiss()` |
| `desktop-activity` | `Live\Activity` | ✓ | `desktop-activity.js` → `desktopActivity` | `record()` — every fact of the `MilpaShell` bus, prepended to the stream |
| `desktop-settings` | `Live\SettingsScreen` | ✓ | `desktop-settings.js` → `desktopSettings` | `save()` (guarded POST; «Saved» only on 2xx, through `settings.saved`), `discard()`, `setTheme()` / `isTheme()` |
| `desktop-auth` | `Live\AuthOverlay` | ✓ | `desktop-auth.js` → `desktopAuth` | `enter()` (guarded `POST /desktop/sessions`, reload only on 2xx), `dismiss()`; publishes `MilpaLive.desktop.auth` |
| `desktop-session-strip` | `Live\SessionStrip` | ✓ | — | its controls call the **sidebar's** module: one ceremony, no copy |
| `desktop-composer` | `Live\ComposerBar` | ✓ | `desktop-composer.js` → `desktopComposer` | the field (`milpaField.reset/change`), `composer.draft`, the token count, `send()` (command vs prompt), `stop()`, the mode chip and `applyMode()` |
| `desktop-conversation` | `Live\Conversation` | ✓ | `desktop-conversation.js` → `desktopConversation` | `append(kind, opts)` — clone the kind's prototype, let the kind fill it; `onClick()` (ONE delegated click for every message component); consumes `desktop.notice`; owns the verdict's words (`tip`/`label`) |
| `desktop-thinking` | `Live\Thinking` | ✓ | `desktop-thinking.js` → `messages.thinking` | `delta()` / `end()` — the block's life across a turn — and its collapse |
| `desktop-agent-message` | `Live\AgentMessage` | ✓ | `desktop-agent-message.js` → `messages.agent` | `renderMarkdown()` (safe subset over escaped text), Copy, Regenerate, and the verdict row that rides the last answer |
| `desktop-tool-call` | `Live\MessagePrototypes` | ✓ | `desktop-tool-call.js` → `messages.tool` | the one-line summary, the pretty-printed raw, the collapse |
| `desktop-result-claim` | `Live\MessagePrototypes` | ✓ | `desktop-result-claim.js` → `messages.result` | the judgement's state, mark, badge, tooltip and `aria-label` |
| `desktop-user-message`, `desktop-task`, `desktop-system-notice` | `Live\MessagePrototypes` | ✓ | — | filled by the thread: one region, one `textContent`, no interaction of their own |
| `desktop-work-board` | `Live\WorkBoard` | ✓ | `desktop-work-board.js` → `desktopWorkBoard` | the five drag events, **delegated on the board's root**, and the guarded `POST /desktop/work` that persists a moved card; the drag's look is CSS state (`work-card--carried`, `work-col--over`) |
| `desktop-capabilities` | `Live\CapabilitiesScreen` | ✓ | `desktop-capabilities.js` → `desktopCapabilities` | the two-step install (`428` + `Confirm-Token`), cloning the **server-rendered** confirm box (`#milpa-cap-confirm-proto`) and reporting what the house answered |
| `desktop-screens` | `Live\ScreenPreview` | ✓ | `desktop-screens.js` → `desktopScreens` | `preview()` from the live route the server wrote on the button, a chip's exact path, Enter in the name box |
| `desktop-decisions` | `Live\DecisionsInbox` | ✓ | `desktop-decisions.js` → — | a question parked while the page is open, cloned from `#milpa-decision-proto` into the always-present list |
| `desktop-skills` | `Live\SkillsScreen` | ✓ | — | read-only: the skills and the specialist roles. Its whole declaration is a stylesheet |
| `desktop-statusbar` | `Live\StatusBar` | ✓ | — | a pure projection: the connection **binds** `conn.label` / `conn.state`, the counters bind `session.status`, the model comes from the app's configuration |
| `desktop-context` | `Live\Context` | ✓ | — | the grid a plugin's contributed panels sit in |

Five modules are declared by the **page**, not by a renderer, because none of them is a surface — nothing
renders them:

| Module | Hangs off | What it owns |
|---|---|---|
| `desktop-guard.js` | `MilpaLive.desktop` | the copy (`tr`), the fetch discipline (`guarded` / `guardedFlow` / `failed`), the `desktop.notice` signal, the one document click listener (`onDismiss`) |
| `desktop-shell-bus.js` | `window.MilpaShell` (and `MilpaLive.desktop.bus`) | `on` / `onAny` / `emit`, `onStatus` / `status` (which writes `conn.state` and `conn.label`), `panel(id)`. It is its **own** module because the bus is the Desktop's published extension point — five shipped modules and every plugin panel reach for it — not a private channel between two of them |
| `desktop-hub.js` | `MilpaLive.desktop.hub` | the ONE `EventSource`, opened on `DOMContentLoaded` (after every deferred module has subscribed) from the URL in `#milpa-desktop-hub`, and `translate(env)` — a `ShellEvent` republished unchanged, a governed turn's `kind` projection mapped to the facts the shell already renders, and a parked question turned into a notice **plus** `decision.parked` |
| `desktop-turn.js` | `MilpaLive.desktop.turn` | `run(text)` — the ONE `POST /agent` — `regenerate()`, `stop()`, the `session.working` signal (**set by the turn itself**, so a Desktop with no hub still shows one running), the pause a parked turn reports, and the counters the turn reports (`session.turns/steps/tokens`, `context.used`) |
| `desktop-commands.js` | `MilpaLive.desktop.commands` | `parse()` / `run()` for `/goal`, `/mode`, `/help` and every user-invocable skill, `call()` over the op's http projection, the failure line, and the completion popup with its keyboard |

One more component is declared but never painted on this page: **`desktop-agent-guest`** — the region this
package contributes to the admin (`?embed=1` in a frame, greenhouse `decisions/0210`). Its stylesheet and its
module are declared here like any other's and served by the same route, but its **renderer loads them itself**
with a `<link>` and a deferred `<script>`, because milpa/admin is the host there and a guest cannot reach the
host's emitter. The frame keeps its `src` in the markup, so the Agent is there with no JavaScript at all; the
module only reports the case where the Desktop returns **no document** (the app is down — the browser's own
error page is cross-origin, so `contentDocument` is null).

The client modules are measured by execution: `npm test` (or `node --test 'tests/js/**/*.test.mjs'`,
Node ≥ 22, nothing to install) loads the shipped files **verbatim** into a stub page, hands each factory the
`$store` and `$root` Alpine would, and runs it — the tab switch, the gate fill, the guarded save, the theme,
the search filter, the session ceremony, the activity stream, every message kind rendered from its own
prototype, the composer's routing of a command versus a prompt, the turn's 401 → sign-in and 403 → notice,
the completion popup's keyboard, the bus's translation of a hub envelope, the capabilities `428` two-step,
the work board's drag and the live decisions inbox are all asserted by running them.

Two PHP gates hold the client side to the server's: `Tests\I18n\ClientCopyTest` reads **every dotted literal**
a shipped module carries and fails unless the catalog answers it, the page seeds it as a signal, or it is one
of a dozen declared non-keys (the bus's fact types) — so a key reached through a variable or a ternary is
covered too. `Tests\DomContractTest` reads every `#id` and `[data-…]` hook the modules reach for and fails
unless the **server prints it**, in both directions for the thinking prototype — because the node harness
builds its own idea of the renderers' markup, and green over a stub is not evidence about the served page.

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

**Upgrading (0.50.0).** The conversation, the composer, the transport and the last four screens become
declared views — **the shell page now carries no executable script at all** — and a review of that move,
by execution in a real browser against a real agent, corrected what it found. Nothing you *configured*
changes meaning. **Five** things a user can see behave differently (all five were already broken; each is
named below under *What the review corrected*) and **twenty** change shape. Two gates were added with them:
`ClientCopyTest` now reads every dotted literal a module carries, not only the ones written as a literal
first argument to `tr()` — it was missing nine live keys — and `DomContractTest` holds every `#id` and
`[data-…]` a module reaches for against the markup the server actually prints.

*What the review corrected (behaviour, not shape):*

R1. **A parked turn is said as a pause.** `POST /agent` answers a parked question with `ok:true` **and**
    `paused:true`, and the module tested `ok && answer` first — so the question rendered as an ordinary
    agent message and the session looked finished while it was waiting. The pause is tested first now: the
    answer is still shown, the pause line after it, and `session.turns` does **not** count a turn that has
    not closed (its steps and its real tokens still count, because they were really spent).
R2. **The turn sets its own `session.working`.** The hub's `session.state` fact was the only writer, so on
    a Desktop with **no Mercure hub** a running turn was invisible: the send button never became `■` and
    the topbar kept reading «Ready». `run()` sets it on and clears it when the turn comes back or fails.
R3. **A 401 that names no door still reaches sign-in.** The Desktop's own routes answer
    `{"signin":"…"}`; app-runtime's operation doors (`/agent`, `/agent/goal`, `/skill/invoke`) answer a
    bare `MILPA_UNAUTHENTICATED`, so a session that expired mid-page printed a raw runtime error at the
    human with no way back in. The shell now writes the app's sign-in path into `#milpa-desktop-guard`
    (only when the Desktop is behind the passkey gate) and the guard falls back to it. A Desktop on the
    loopback gate declares none and still reports, rather than navigating nowhere.
R4. **Preview reports a screen the wire does not serve.** `/live/page?component=<name>` answers 404 with an
    empty body for a name the wire does not know, and the iframe just went white. The wire is asked first,
    through the shared guard, and a non-2xx is a notice (`preview.failed` / `preview.unreachable`).
R5. **The completion popup survives a re-rendered composer bar.** It was the one module that resolved its
    element once at load and bound listeners to it — the exact anti-pattern this release removed from the
    work board. It re-resolves per call, and both its click-to-fill and its click-away are the guard's one
    document listener. Reachable today through `desktop.composer_bar.after_render`.

*The transport and the last screens (phase D):*

A. **The bus and the hub connector are modules.** `window.MilpaShell` is still the published extension
   point, with the same API (`on`, `onAny`, `emit`, `onStatus`, `status`, `panel`) — it is simply defined
   by `desktop-shell-bus.js` instead of by an inline script. Because that script is `defer`red, the bus
   exists when deferred code runs, not during parsing: a plugin that used `MilpaShell` from a **non-deferred**
   inline script of its own must defer it too. `MilpaShell.session(env)` and `MilpaShell.addDecision(q)` are
   **gone**: the envelope translation is `MilpaLive.desktop.hub.translate(env)`, and a parked question is now
   the `decision.parked` fact — the sidebar's module ticks the badge and the inbox's module adds the card.
B. **The hub's URL is DATA.** `#milpa-desktop-hub` (`{"url":"…"}`, or `{}` with no hub) replaces the inline
   `new EventSource(…)`. One connection, two exact topics, still opened on `DOMContentLoaded`.
C. **Four screens are components.** `desktop-capabilities`, `desktop-skills`, `desktop-screens` and
   `desktop-decisions` each render their own `.view` root with their own `data-view` key — the sidebar swaps
   between them exactly as before — plus `desktop-statusbar` for the bottom bar. Each has `before_render` /
   `after_render` events (`desktop.capabilities.*`, `desktop.skills.*`, `desktop.screens.*`,
   `desktop.decisions.*`, `desktop.statusbar.*`) and a signed envelope, like every other surface. The
   element ids a plugin might have reached for are unchanged (`milpa-capabilities`, `milpa-skills`,
   `milpa-roles`, `milpa-screens`, `milpa-preview-name`, `milpa-preview-go`, `milpa-preview-frame`,
   `milpa-decisions-list`, `milpa-decisions-empty`).
D. **The confirm box is a prototype.** The capabilities two-step no longer builds its box with `innerHTML`:
   the server renders `#milpa-cap-confirm-proto` and the module clones and fills it — the same discipline as
   a conversation message kind.
E. **The decisions list is always rendered.** `DecisionsInboxView` prints `<ol id="milpa-decisions-list">`
   even when nothing is parked, with the empty line **next to it**; CSS hides the line once a card lands.
   `DecisionsInboxView::html()` takes a second argument (the empty line's words, defaulted to the English it
   used to hardcode) so the screen says it in the declared locale.
F. **The connection is a signal.** `conn.state` (`connecting` / `live` / `offline`) and `conn.label` are
   written by `MilpaShell.status()` and **bound** by the status bar. Nothing reaches for `#milpa-conn`; the
   element is gone. The work board's cards and columns carry classes (`work-card`, `work-col`) instead of
   inline `style=`, and the drag's look is `work-card--carried` / `work-col--over`.
G. **The shell's `<style>` holds only the shell's frame.** The capability cards, the decision cards, the
   skill and role cards, the preview chips, the command popup, the composer box, the status bar, the work
   board, the panel grid and the interrupted notice all moved to their component's own stylesheet — so a
   plugin that overrode one of those rules by outweighing the shell's `<style>` now overrides a declared
   stylesheet that is emitted **after** it. `ul.feed` was deleted: nothing rendered it.

*The conversation and the composer (phase C):*

1. **The composer is a component.** `desktop-composer`, rendered by `Live\ComposerBar` (moved out of
   `ShellController::composer()`), with `before_render` / `after_render`
   (`desktop.composer_bar.before_render`, …) like every other surface. Its element ids are unchanged
   (`milpa-send`, `milpa-mode-chip`, `milpa-mode-menu`, `milpa-charcount`, `milpa-command-list`,
   `composer-input`), so a plugin that reached for one still finds it. `ShellController`'s constructor takes
   one more optional argument before `$live` (`?ComposerBar $composerBar`); a host that constructs the
   controller positionally with its own registry must pass it (or `null`).
2. **Five more component modules, two page modules.** `desktop-conversation.js`, `desktop-thinking.js`,
   `desktop-agent-message.js`, `desktop-tool-call.js` and `desktop-result-claim.js` are declared by their
   renderers; `desktop-turn.js` and `desktop-commands.js` are declared by the page, next to
   `desktop-guard.js`, because neither is a surface. Anything that pinned the shell's literal functions
   (`appendMessage`, `renderMarkdown`, `runTurn`, `send`, `parseCommand`, `applyMode`, …) now finds them in
   `/desktop/assets/c/<module>.js`.
3. **A message kind is extended at its own component.** `MilpaLive.desktop.messages[kind]` is
   `{ proto, fill(root, opts, region), click(event), … }`; registering a kind there is how a plugin adds a
   message type, and the thread clones and dispatches to it without knowing it exists.
4. **The agent session is DATA.** `#milpa-desktop-session` (`{"agent":"desk-…"}`) replaces the `var
   agentSession = '…'` the page used to bake into its script. The `milpa_agent_sid` cookie is unchanged.
5. **The conversation's and the composer's copy is catalog copy.** The verdict's sentences, the thinking
   block's elapsed, the turn's pause hint, the token count and every command's answer are keys in
   `src/I18n/Catalog.php` (`verdict.*`, `thinking.elapsed`, `turn.*`, `composer.tokens`, `command.*`,
   `op.*`) in en/es. `MilpaLive.desktop.tr()` now takes as many arguments as the key has `%s`. The review
   found four sentences this had missed, and they are keys now too: the session's state word
   (`session.state.working` / `session.state.idle` — the turn's module wrote a hardcoded «Working» into a
   value `Live\Topbar` renders), the interrupted-run notice (`conversation.interrupted`), the composer's
   placeholder and its two control labels (`composer.placeholder` / `.attach` / `.send` / `.stop`), and the
   preview's failures. **Still English wherever the app is Spanish:** the composer's two floating panels
   («Session», «Steps», «Tool calls», «State», «Context», «% used», «free», the model line), the four
   computed templates the page seeds (`session.summary`, `session.counters`, `context.usage`,
   `session.status` — «turns», «tools», «steps», «tokens»), and the Activity tab's own words. Those are
   named here because they are not done, not because they are fine.
6. **The thread binds one click.** `#milpa-chat` carries `x-data="desktopConversation()"` and
   `@click="onClick($event)"`. A plugin that attached its own listener to `#milpa-chat` still works; one
   that relied on the page's `chat.addEventListener('click', …)` being the only handler should register a
   message kind instead.

*What the review changed in the markup (shape, from the same pass):*

7. **The composer bar has a stylesheet.** `Live\ComposerBar` carried 35 `style="…"` attributes after the
   phase that removed the work board's; they are classes in `desktop-composer.css` now (`.composer-panel`,
   `.composer-panel--session|--context`, `.composer-row`, `.composer-meta`, `.composer-mode__*`,
   `.composer-chip`, `.composer-model`, `.milpa-composer-box`). The two floating panels' class attribute
   grew a modifier (`class="composer-panel composer-panel--context"`), and the context meter's fill is two
   custom properties (`--composer-meter`, `--composer-meter-color`) instead of a `width`/`background` pair
   — a plugin that overrode one of those inline styles now overrides a class.
8. **The interrupted-run notice belongs to the conversation.** It was hand-written by `ShellController`
   while its stylesheet lived in `desktop-conversation.css`; `Live\Conversation` renders it now (its
   constructor takes `?DesktopData` and `?Catalog` after `$events`, and `ConversationComponent`'s contract
   carries an `interrupted` prop and state). The `<!--INTERRUPTED-->` template marker is gone.
9. **One authority for the permission mode's words.** `ComposerBar::MODE_LABELS` /
   `Topbar::MODE_LABELS` / the shell's own copy in `liveSignals()` were three hardcoded maps of the same
   three English strings; all three read `ComposerBar::modeLabel()` over the catalog's existing
   `settings.autonomy.*` keys, so the chip, its menu, the topbar and the Settings screen cannot disagree —
   in any locale. `ComposerBar::MODE_KEYS` is the public map (mode → catalog key).
10. **The confirm box's action row is `data-cap-actions`.** `data-cap-row` already meant «an available
    capability's card» in `CapabilityCatalogueView`, and the box is appended *into* that card: one
    attribute, two meanings, one selector away from picking the wrong node.
11. **The Activity tab's empty row is marked.** `data-activity-empty` on the `<li>`; the module used to
    recognise it by matching the English «no facts», which would have outlived the sentence's translation.
12. **`ComposerField` takes a `?Catalog`** (fifth argument) so the live textarea's placeholder is catalog
    copy; `ComposerBar` takes one too (fifth argument).
13. **The admin's Agent region declares its files.** `desktop-agent-guest.css` / `.js` replace the inline
    `<style>` and the `onload=` / `onerror=` attributes on the frame — the only executable inline script
    this package still emitted anywhere. The region's rules are scoped by the `.desktop-agent` class
    instead of the section's id, so a host stylesheet that targeted `#<section-id> .desktop-agent__frame`
    should target the class.

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
