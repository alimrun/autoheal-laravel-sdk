# Changelog

All notable changes to this package are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-13

The first release.

### Added

- Reports exceptions and error logs from a Laravel 11, 12 or 13 application on PHP 8.3 or later, as schema version 1 events. The SDK stays off until a key and an endpoint are set.
- `autoheal:deploy`, `autoheal:heartbeat` and `autoheal:health` report deployments, heartbeats and health checks, and `autoheal:test` sends a test exception. The `AutoHeal` facade reports the same events from code.
- `autoheal:heartbeat --every=` and `AutoHeal::heartbeat(every: ...)` state how often a heartbeat is expected, from 60 seconds to 7 days, so AutoHeal can notice a missed one.
- Events are buffered, at most 50 per request, job or command, and sent after the response, job or command, over HTTP or through a queued job.
- A pause after a failed send or a refused key, shared through the application cache, with one warning on the application's log. No AutoHeal failure reaches the application.
- Redaction of sensitive keys and secret-looking values before anything is sent, using cases shared with the platform.
- An opt-in cache hook, `POST /autoheal/cache/{cache}`, for the AutoHeal agent's `clear_cache` recovery action. It answers only loopback requests without forwarding headers that carry a token of at least 32 characters, and clears only listed caches: compiled views or one configured cache store.
- A Testbench workbench with a sample route for trying the SDK against a local platform.
- Published under the MIT license, with Composer metadata for Packagist.

[0.1.0]: https://github.com/alimrun/autoheal-laravel-sdk/releases/tag/v0.1.0
