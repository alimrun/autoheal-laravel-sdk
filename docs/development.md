# Development

This page is for working on the SDK itself.

## Setup

```bash
git clone https://github.com/alimrun/autoheal-laravel-sdk.git
cd autoheal-laravel-sdk
composer install
```

The package is tested with Orchestra Testbench, which runs it inside a Laravel 13 application. You need a PHP version that Laravel 13 supports.

## Checks

```bash
composer check   # Pint, PHPStan and Pest
```

`composer check` runs, in order:

| Tool | Command | What it checks |
|---|---|---|
| Pint | `./vendor/bin/pint --test` | Code style: PSR-12 with the rules in `pint.json`. |
| PHPStan | `./vendor/bin/phpstan analyse --memory-limit=1G --no-progress` | Types, at level 6 with Larastan, over `src/`. |
| Pest | `./vendor/bin/pest` | The test suite. |

To fix the code style instead of only checking it:

```bash
./vendor/bin/pint
```

To run only the tests:

```bash
composer test
```

## Tests

- `tests/Unit` covers the event builder, the redactor and the stack frames.
- `tests/Feature` covers configuration, exception and log capture, the commands, the facade, queued delivery, failure isolation and the cache hook.
- `tests/Fixtures/telemetry-event.schema.json` is the platform's published event schema. Every event the tests build is validated against it.
- `tests/Fixtures/redaction-cases.json` is shared with the platform, and both test suites run every case. Change it on both sides together.

The tests never call a real AutoHeal: they fake the HTTP client, except for the cases that check an unreachable endpoint.

## Sample application

The Testbench workbench is a small Laravel application with a route, `/autoheal/sample-exception`, that throws a `RuntimeException` on purpose.

```bash
cp workbench/.env.example workbench/.env   # then set AUTOHEAL_KEY
composer serve
```

`workbench/.env.example` points `AUTOHEAL_ENDPOINT` at `http://localhost:8100/api/v1`, a platform running locally. `workbench/.env` is git-ignored, so the key stays on your machine.

Open `/autoheal/sample-exception`: the exception appears on the environment's events page. Point `AUTOHEAL_ENDPOINT` at an address that does not answer, and the route answers exactly as before.

## What ships

`tests/`, `workbench/` and the tool configuration files are listed in `.gitattributes` as `export-ignore`, so they are left out of the archive Composer downloads.
