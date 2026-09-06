# Milpa Desktop — parity roadmap (from the CLI wrapper to the canonical plugin)

Two Desktops exist. This roadmap closes the gap between them, in the canon's shape — not by porting
the old one's workarounds.

- **The old wrapper** — [`getmilpa/desktop`](https://github.com/getmilpa/desktop) (`milpa-desktop-shell`
  v0.4.0): a standalone **Electron shell** whose renderer is `file://`. Because `file://` cannot talk to a
  backend, its **main process** owned everything — a Docker container's lifecycle, a Bearer credential
  injected via `webRequest`, host key custody, and a 20-handler IPC bridge (`milpa:component`, `milpa:live`,
  `milpa:api`, `milpa:drive`, `milpa:answer`, `milpa:events`, …) that proxied the renderer to the container.
- **The canon** — `getmilpa/desktop-app`: a **founded-app plugin** that server-renders the shell with Milpa
  Components and serves it over HTTP; the agent runs through the governed `agent` HTTP surface
  (greenhouse decisions/0190), state streams over Mercure, and `examples/electron` is a ~118-line
  same-origin window (`php -S … public/router.php` + a `BrowserWindow` on `http://localhost:<port>/desktop`).

**The reframe:** the canon serves over a real HTTP origin, so the renderer fetches the backend directly.
The old IPC bridge was a `file://` workaround — **do not port it** (see "Explicitly out of scope"). What is
durable is (a) how a user *gets and runs* a Desktop, (b) the identity/trust spine, and (c) the *surfaces*
the old wrapper grew that the canon never built.

---

## The one architectural question (answer this first — it reorders everything below)

> **What is the distributable's backend shape?**

The old binary shipped nothing but the shell and pulled a **public Docker image**
(`ghcr.io/getmilpa/framework:dev`) on first launch — Docker the only prerequisite, framework-inside-the-image.
The canon's backend is a *founded app that hosts itself*. So a downloadable canon Desktop can be:

- **(A) Container-backed** (old model): the binary orchestrates a container running a founded image. Keeps
  keys/credentials on the host; framework updates arrive by re-pulling. Needs Docker.
- **(B) Bundled-runtime**: the binary ships a PHP runtime + the founded app and runs `php -S` itself. No
  Docker; heavier binary; framework updates ride the binary.
- **(C) Bring-your-own-app**: the binary is only the window + lifecycle; it attaches to an app the developer
  already runs. Lightest; not a consumer download.

Everything in Phase 1 and 2 (packaging, the credential boundary, key custody) depends on this answer. It is
the first slice. **Recommendation:** (A) for the consumer download (it is what the old wrapper proved), with
(C) staying the developer path `examples/electron` already serves.

---

## What the canon already has (do not re-litigate)

Confirmed present, audited 2026-09-04: the self-hosting shell over HTTP; the whole conversation as Milpa
Components (thinking/user/agent/tool/task/system/result, decisions/0191); the agent over the HTTP surface
(0190) with Mercure streaming; Work / Activity / Context tabs; the consent **gate** as a component; **Sessions**
(wired), **Capabilities** (wired, read-only), **Settings** (wired read+write, persisted to disk); the passkey
**enroll/approve links** wired same-origin (`/webauthn/enroll`, `/webauthn/intent`); event **replay** with
`Last-Event-ID` resume; a **session export** download (`/desktop/export`); real provider **token counts**
(decisions/0192).

---

## Phase 1 — Make it a thing a person can download and run (highest value)

The canon has no distributable; `examples/electron` is a dev convenience. This is the gap to milpahq / real
users, and memory has flagged it for months ("falta empaquetar el binario Electron").

- **P1.1 — Packaging.** electron-builder config → AppImage (Linux) + dmg/zip (macOS). *(old: `package.json`
  `build`, `build-desktop.sh`.)*
- **P1.2 — Release CI.** A `desktop-v*`-tagged workflow that builds Linux+macOS in parallel and publishes a
  public Release with `SHA256SUMS.txt`. *(old: `.github/workflows/desktop-release.yml`.)* Note: the canon's
  own `release.yml` is release-please for the Composer package — the binary needs a **separate** tag lane.
- **P1.3 — Backend lifecycle in the wrapper.** Per the architectural answer above: start the backend on
  launch, stop on quit, survive relaunch mid-session (the old wrapper learned to **reuse a running
  container** because "a session mid-build died with the container on every relaunch"). Reproduce that
  robustness whichever shape wins.
- **P1.4 — DISTRIBUTION.md** — the one-file, Docker-only "download and run" story, plus how to point the
  agent at a model.

## Phase 2 — The identity & trust spine (the desktop is where a human meets the agent — the pin)

The old wrapper kept keys and credentials **off the renderer and outside the ephemeral backend**. The canon
wrapper today loads `http://localhost:<port>` behind the app's door (greenhouse decisions/0209 — loopback by
default, a passkey gate by declaration) but with no such credential boundary of its own.

- **P2.1 — Credential boundary.** If the distributable serves a privileged origin, mint a **scoped
  credential** and keep it in the trusted process (the old minted a `coa token:new` with per-component +
  `agent:*` scopes and injected it via `webRequest.onBeforeSendHeaders`; the renderer never saw it). Reframe
  for the HTTP model — the point is the renderer must not hold what it must not touch.
- **P2.2 — Host key custody** (greenhouse decisions/0121). Keys live on the host and **outlive** the backend:
  a mounted `GNUPGHOME` for software keys, and **YubiKey/smartcard** reachability so the private key never
  leaves the hardware. Pairs directly with the `a2a:emit` YubiKey cabo (memory `a2a-custodia-llave`). The
  canon has *zero* key-custody code today.
- **P2.3 — Passkey gate-resume.** The enroll/approve UI is wired; the remaining backend step (noted in the
  old CHANGELOG) is **the resume that clears the agent's gate once the ceremony authorizes** — close that
  loop end-to-end in the canon.

## Phase 3 — Surfaces the old wrapper grew that the canon never built

- **P3.1 — Decisions inbox (dead pane today).** The nav item exists but the count is hardcoded `0` and the
  list is never populated. Wire it to **pending questions across all sessions**, with the sidebar badge. High
  value / low cost — the shell already renders the live gate; this is the cross-session backlog view.
- **P3.2 — Declared-screen live preview.** The old wrapper's headline feature: type the name of a screen the
  agent declared (`screen:declare`, graduated in the LivePlugin) and **see the UI it is building**, rendered
  live — *"how does it look?", answered.* The canon has no such surface.
- **P3.3 — Inspector / permissions ledger.** A docked drawer projecting the session's **plan steps** and its
  **permissions** (granted / needs-signature / revoked), plus the actionable-blocker card. The canon shows
  Work/Context but not the permissions ledger.
- **P3.4 — Capabilities: from read-only to actionable.** Add opt-in **`capabilities.enable`** (through the
  gate) and a **skills** list. Today the pane only lists what's installed.
- **P3.5 — Specialist agent roles.** `agent:role:list` / `agent:role:declare` — compose and project
  specialist agents. Absent in the canon.
- **P3.6 — Debt-signal surface** (greenhouse decisions/0183, primitive #5). Aggregate `session.debt_signaled`
  kinds into a pane so the house's own debt is visible where the human works. The signal exists server-side;
  the surface does not.
- **P3.7 — Interrupted-run detection on load.** Detect a prior agent run that died with the session and
  surface it (the old flagged an INTERRUPTED run and offered no silent auto-resume — matches memory
  `desktop-bugs-macos` BUG2). The canon's Stop is client-only and there is no on-load detection.
- **P3.8 — Onboarding / launch screen.** Pick app + provider ("found a new app"), runtime-coming-up state —
  the first-run flow a downloaded binary needs.

## Phase 4 — Compliance & polish

- **P4.1 — i18n (en/es).** **This is a house-rule gap, not a nicety.** The house rule (greenhouse
  decisions/0138) mandates every user-facing surface be internationalized (English default, Spanish
  selectable); the canon shell hardcodes `<html lang="en">`, while the *old* wrapper already had a locale
  system (decisions/0139). The door (decisions/0209) seeded a catalog (`desktop.locale`, the chips and the
  guard's notices); the rest of the shell's copy still migrates key by key.
- **P4.2 — Export enrichment.** The old export was **categorized with token weight per part** (system / user /
  summary / tools / thinking) — a context-budget audit, not just a dump. Enrich `/desktop/export` toward that
  (now that real token counts land, decisions/0192).
- **P4.3 — Theme toggle** — confirm the topbar "Theme" control actually toggles + persists (the old had an
  explicit toggle); minor.

---

## Explicitly out of scope (the `file://` workarounds — the HTTP surface already replaced these)

- The IPC bridge as a transport: `milpa:component`, `milpa:live`, `milpa:api`, `milpa:drive`, `milpa:answer`,
  `milpa:events`, `milpa:show`, `milpa:owner`. The renderer is same-origin now — it fetches the backend and
  subscribes to Mercure directly. Porting the bridge would re-introduce a workaround for a constraint the
  canon does not have.
- `docker exec` as the agent-drive path — superseded by the governed `agent` HTTP surface (decisions/0190).
- The localhost-window trick for WebAuthn — needed only because the old renderer was `file://`; the canon
  already serves the ceremony pages same-origin.

## Suggested first three slices

1. **Answer the architectural question** (backend shape) with a throwaway spike of each viable path — the
   slice that reorders the rest.
2. **P3.1 Decisions inbox** — closes a visibly-dead pane, no new architecture, immediate UX win.
3. **P1.1 + P1.2 packaging + release CI** — turns the plugin into something a person can download, the
   month-old flagged gap.

---
_Audited against `getmilpa/desktop` (main) and `getmilpa/desktop-app` (main), 2026-09-04._
_Apache-2.0 · © Rodrigo Vicente - TeamX Agency_
