# Changelog

All notable changes to milpa/desktop-app are documented here. This project adheres to
[Semantic Versioning](https://semver.org/) and [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- First slice (greenhouse decisions/0188): `DesktopAppPlugin` — a plugin that SERVES the app's own
  shell over HTTP at `GET /desktop`, so an Electron or browser host loads the UI at a real origin
  instead of a `file://` renderer. The served shell shares its origin with the app's `/webauthn/*`
  doors, so the passkey ceremony runs in-origin. `ShellController` serves the shell; the reactive
  event bus (websockets / milpa/mercure), the UI events other plugins hook, and the full renderer
  migration are the named arc.
