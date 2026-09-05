# Milpa Desktop — Electron host

A minimal, generic Electron host for any Milpa app running `milpa/desktop-app`. It does two things:

1. starts the app's HTTP server (PHP's built-in server bound on `127.0.0.1`, via the app's
   `public/router.php` — bound on the IP because that server listens on one address family only), and
2. loads `GET /desktop` in a native window at a **real origin** (`http://localhost:<port>` — `localhost`,
   not an IP: WebAuthn accepts it as a relying-party id, and a passkey gate declared under
   `desktop.middleware` matches it with `passkey.rpId = 'localhost'`).

The Milpa Desktop is not an Electron app that drives a separate Milpa — it is a Milpa that serves its
own shell over HTTP. This host just wraps that shell in a native window, so the passkey ceremony, the
consent gate and the live components all run same-origin (greenhouse `decisions/0188`).

## Use

```bash
npm install
MILPA_APP_DIR=/path/to/your/milpa-app npm start
```

## Configuration (env vars)

| Var | Meaning | Default |
| --- | --- | --- |
| `MILPA_APP_DIR` | Path to the Milpa app (must contain `public/router.php`). | `../../` of this dir |
| `MILPA_PORT` | Port for the app server. | an OS-chosen free port |
| `MILPA_PHP` | The `php` binary. | `php` |
| `MILPA_CAPTURE` | If set, write a PNG of the loaded window to this path and quit (headless proof). | — |

The window's lifecycle owns the server: closing the window (or the server dying) quits the app.
