# Changelog

All notable changes to milpa/desktop-app are documented here. This project adheres to
[Semantic Versioning](https://semver.org/) and [Keep a Changelog](https://keepachangelog.com/).

## [0.9.0](https://github.com/getmilpa/desktop-app/compare/v0.8.0...v0.9.0) (2026-09-03)


### Features

* implement the canonical Desktop wireframes with the real @milpa/design system (evidence/0479) ([5ed5120](https://github.com/getmilpa/desktop-app/commit/5ed5120b8f150ff6e6ae0b2217c4460682016226))

## [0.8.0](https://github.com/getmilpa/desktop-app/compare/v0.7.0...v0.8.0) (2026-09-03)


### Features

* the Milpa Desktop dashboard — the whole UI as reactive Milpa components (evidence/0478) ([e6312ff](https://github.com/getmilpa/desktop-app/commit/e6312ff46b98feee31dae77f0bc296ded2fbeff5))

## [0.7.0](https://github.com/getmilpa/desktop-app/compare/v0.6.0...v0.7.0) (2026-09-03)


### Features

* the consent gate as a reactive component — agent asks, human approves at a real origin (evidence/0477) ([8ad3547](https://github.com/getmilpa/desktop-app/commit/8ad35479e38fda033e09ca51aa29def8eedf52f9))

## [0.6.0](https://github.com/getmilpa/desktop-app/compare/v0.5.0...v0.6.0) (2026-09-03)


### Features

* the shell is a reactive renderer — a client component runtime driven by the live feed (evidence/0476) ([a302db6](https://github.com/getmilpa/desktop-app/commit/a302db68bb9f2de7ece5d633ee2516c901c66d77))

## [0.5.0](https://github.com/getmilpa/desktop-app/compare/v0.4.0...v0.5.0) (2026-09-03)


### Features

* wire the live feed to a Mercure hub — the poll is gone when a hub is configured (evidence/0475) ([599203a](https://github.com/getmilpa/desktop-app/commit/599203a9995e28f1f1892c743dd3760ab88adf96))

## [0.4.0](https://github.com/getmilpa/desktop-app/compare/v0.3.0...v0.4.0) (2026-09-03)


### Features

* the live feed is a continuous SSE stream, not short-poll (evidence/0473) ([769a3d6](https://github.com/getmilpa/desktop-app/commit/769a3d6914fcefa6f4712cdc741b0763153be575))

## [0.3.0](https://github.com/getmilpa/desktop-app/compare/v0.2.0...v0.3.0) (2026-09-03)


### Features

* a live event feed — plugins push shell changes to the browser over SSE ([dd5aba3](https://github.com/getmilpa/desktop-app/commit/dd5aba320beda47d5e7eb9d1276bb04a338e37b5))

## [0.2.0](https://github.com/getmilpa/desktop-app/compare/v0.1.1...v0.2.0) (2026-09-03)


### Features

* the shell is extensible — other plugins contribute UI through an event ([ac50b9b](https://github.com/getmilpa/desktop-app/commit/ac50b9b53900f454db9424041d0ace9182ecf212))

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
