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

- `GET /desktop` — the app's own shell UI, served over HTTP. Point an Electron `loadURL` (or a
  browser) at it.

## The arc

This first slice serves the shell and proves the seam. Named next (`decisions/0188`): the full
renderer as Milpa components, the reactive event bus (websockets / `milpa/mercure`), and the UI
events other plugins hook to change the shell — so *everything is built from Milpa components*.

## License

Apache-2.0 · © Rodrigo Vicente - TeamX Agency

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=desktop-app)**.
