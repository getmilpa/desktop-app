# Changelog

All notable changes to milpa/desktop-app are documented here. This project adheres to
[Semantic Versioning](https://semver.org/) and [Keep a Changelog](https://keepachangelog.com/).

## [0.1.1](https://github.com/getmilpa/desktop-app/compare/v0.1.0...v0.1.1) (2026-09-03)


### Bug Fixes

* the plugin declares #[PluginMetadata] so the runtime can boot it ([b5361d5](https://github.com/getmilpa/desktop-app/commit/b5361d53346b6ef13240adfebd4b11d116718755))

## 0.1.0 (2026-09-03)


### Features

* milpa/desktop-app — a Milpa app serves its own shell (decisions/0188) ([4fb0e78](https://github.com/getmilpa/desktop-app/commit/4fb0e78c0447be35c466a4fa3722dc7d1d4c04c4))


### Miscellaneous Chores

* release milpa/desktop-app as 0.1.0 ([12a3598](https://github.com/getmilpa/desktop-app/commit/12a35986847c5a2bebc08595fe81ad97723df63d))

## [Unreleased]

### Added
- First slice (greenhouse decisions/0188): `DesktopAppPlugin` — a plugin that SERVES the app's own
  shell over HTTP at `GET /desktop`, so an Electron or browser host loads the UI at a real origin
  instead of a `file://` renderer. The served shell shares its origin with the app's `/webauthn/*`
  doors, so the passkey ceremony runs in-origin. `ShellController` serves the shell; the reactive
  event bus (websockets / milpa/mercure), the UI events other plugins hook, and the full renderer
  migration are the named arc.
