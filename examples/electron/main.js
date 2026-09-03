// A minimal Electron host for a Milpa app that runs milpa/desktop-app.
//
// The Milpa Desktop is NOT an Electron app that drives a separate Milpa — it is a Milpa that serves
// its own shell over HTTP (`GET /desktop`). This host does exactly two things: it starts the app's
// HTTP server (PHP's built-in server, via the app's public/router.php) and loads `/desktop` in a
// native window at a REAL origin (http://127.0.0.1:<port>), so the passkey ceremony, the consent
// gate and the live components all run same-origin — the file:// constraint is dissolved by
// construction (greenhouse decisions/0188).
//
// Configure with env vars:
//   MILPA_APP_DIR   path to the Milpa app (must contain public/router.php). Default: ../../ of cwd.
//   MILPA_PORT      port for the app server. Default: an OS-chosen free port.
//   MILPA_PHP       php binary. Default: "php".
//   MILPA_CAPTURE   if set, write a PNG of the loaded window to this path and quit (headless proof).

'use strict';

const { app, BrowserWindow } = require('electron');
const { spawn } = require('node:child_process');
const net = require('node:net');
const http = require('node:http');
const path = require('node:path');
const fs = require('node:fs');

const APP_DIR = process.env.MILPA_APP_DIR || path.resolve(__dirname, '..', '..');
const PHP = process.env.MILPA_PHP || 'php';

let phpProc = null;

function freePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.unref();
    srv.on('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const { port } = srv.address();
      srv.close(() => resolve(port));
    });
  });
}

function waitForServer(url, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  return new Promise((resolve, reject) => {
    const tick = () => {
      const req = http.get(url, (res) => {
        res.resume();
        resolve();
      });
      req.on('error', () => {
        if (Date.now() > deadline) reject(new Error('app server did not come up: ' + url));
        else setTimeout(tick, 120);
      });
    };
    tick();
  });
}

function startPhp(port) {
  const router = path.join(APP_DIR, 'public', 'router.php');
  if (!fs.existsSync(router)) {
    throw new Error('no public/router.php under MILPA_APP_DIR=' + APP_DIR);
  }
  phpProc = spawn(PHP, ['-S', '127.0.0.1:' + port, '-t', 'public', 'public/router.php'], {
    cwd: APP_DIR,
    stdio: 'inherit',
  });
  phpProc.on('exit', (code) => {
    // If the app server dies, the window has nothing to show — leave with it.
    if (!app.isQuitting) app.quit();
  });
}

function stopPhp() {
  if (phpProc && !phpProc.killed) {
    phpProc.kill('SIGTERM');
    phpProc = null;
  }
}

async function createWindow() {
  const port = Number(process.env.MILPA_PORT) || (await freePort());
  startPhp(port);
  const base = 'http://127.0.0.1:' + port;
  await waitForServer(base + '/desktop', 15000);

  const win = new BrowserWindow({
    width: 1280,
    height: 840,
    backgroundColor: '#1a140f',
    title: 'Milpa Desktop',
    autoHideMenuBar: true,
    webPreferences: { contextIsolation: true, nodeIntegration: false },
  });

  await win.loadURL(base + '/desktop');

  if (process.env.MILPA_CAPTURE) {
    // Headless proof: the native window actually rendered the served shell.
    const image = await win.capturePage();
    fs.writeFileSync(process.env.MILPA_CAPTURE, image.toPNG());
    app.isQuitting = true;
    app.quit();
  }
}

app.whenReady().then(createWindow);

app.on('before-quit', () => {
  app.isQuitting = true;
  stopPhp();
});

app.on('window-all-closed', () => {
  stopPhp();
  app.quit();
});
