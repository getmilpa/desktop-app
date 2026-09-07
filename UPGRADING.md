# Upgrading

## 0.55.0 — this package is now `milpa/agent-workspace`

**Renamed, not retired.** The code lives on at
[`getmilpa/agent-workspace`](https://github.com/getmilpa/agent-workspace) with its full history and the same 297
tests. `abandoned` points there, so composer will tell you where it went.

```diff
-  "milpa/desktop-app": ">=0.54 <1.0"
+  "milpa/agent-workspace": ">=0.55 <1.0"
```

```diff
-use Milpa\DesktopApp\DesktopAppPlugin;
+use Milpa\AgentWorkspace\AgentWorkspacePlugin;
```

Every `Milpa\DesktopApp\…` class is `Milpa\AgentWorkspace\…`. The plugin's registered name is `AgentWorkspace`,
and the capability id is `agent-workspace`. **Nothing else changed** — the routes, the components, the events and
the behaviour are byte-identical.

## Why

The name lied: it said *desktop* and delivered *agent*.

Measured when the decision was taken: this package declared **twelve routes and all twelve were agent
workspace**, while `milpa/admin` — **six weeks older** — held the section contract, the native sections, the
gate, the settings and the i18n. Its capability even claimed to provide `desktop-shell`, a thing it never
provided.

And greenhouse `decisions/0210` was already titled *"the Desktop as a guest of the admin"*. The inversion had
been decided and built; only the package names still said otherwise.

**The panel is `milpa/admin`. This is a tenant of it, and it is opt-in** — an app has a panel without it.

**The routes stay `/desktop/*`**, and that is not the old lie: `Desktop` is the product a human opens,
`agent-workspace` is the package that provides one of its sections. Both names are true now.

See greenhouse `decisions/0220`.
