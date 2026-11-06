# AutoHeal Laravel SDK

Reports a Laravel application's exceptions, error logs, deployments, heartbeats and health checks to AutoHeal. It is built so that AutoHeal can never slow the application down, break it or leak its secrets.

- Laravel 11, 12 or 13 on PHP 8.3 or later.
- Registers itself through package discovery. Setting a key and an endpoint is the only change the application needs.

## Install

Until the package is published, install it from this folder with a Composer path repository. In the application's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../autoheal-laravel-sdk" }
]
```

Then:

```bash
composer require autoheal/laravel-sdk:@dev
```

To change the defaults, publish the configuration with `php artisan vendor:publish --tag=autoheal-config`.

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `AUTOHEAL_KEY` | none | The SDK key from the environment's page in the AutoHeal dashboard (`ahk_…`). Without it the SDK does nothing. |
| `AUTOHEAL_ENDPOINT` | none | The platform API, such as `http://localhost:8100/api/v1`. Without it the SDK does nothing. |
| `AUTOHEAL_RELEASE` | none | The running release, sent with every event. |
| `AUTOHEAL_SERVICE` | none | The service name, sent with every event. |
| `AUTOHEAL_TRANSPORT` | `http` | `http` sends after the response, job or command. `queue` hands events to a queued job. |
| `AUTOHEAL_LOG_LEVEL` | `error` | The lowest log level that is reported. |
| `AUTOHEAL_ENABLED` | `true` | Set to `false` to turn the SDK off, for example in the application's own test suite. |

The key alone decides which organization, project and environment an event is stored in. The SDK never sends an organization, project or environment identifier.

## What is reported

- **Exceptions** reported through Laravel's exception handler, except those the application lists as not to be reported (`dontReport`). Each event carries the class, message, code, up to 100 stack frames marked as the application's own (`in_app`) or a dependency's, and up to 3 previous exceptions. Laravel's own log line for the exception is not sent again.
- **Error logs** at `AUTOHEAL_LOG_LEVEL` or above that do not carry an exception, with their level, the default log channel and their context.
- **Context:** the request's method, path and route name; the job's class and queue; the command's name; the application environment and the PHP and Laravel versions.

### Deployments, heartbeats and health checks

From a deploy pipeline or the scheduler:

```bash
php artisan autoheal:deploy 2026.11.06-1 --source=github-actions
php artisan autoheal:heartbeat scheduler
php artisan autoheal:health database degraded --latency=840
```

```php
// routes/console.php
Schedule::command('autoheal:heartbeat scheduler --every=60')->everyMinute();
```

These commands send straight away and say whether AutoHeal accepted the event. They always exit successfully, whatever AutoHeal answers, so an AutoHeal outage never fails a deploy or the scheduler. They exit with an error only for input AutoHeal would refuse, such as a name with spaces.

From code, events are buffered and sent with the rest:

```php
use AutoHeal\Laravel\Facades\AutoHeal;

AutoHeal::deployment('2026.11.06-1', 'forge');
AutoHeal::heartbeat('nightly-import', every: 86400);
AutoHeal::healthCheck('database', 'up', latencyMs: 12);
```

Names are 1 to 100 letters, digits, `.`, `_`, `:` or `-`. A heartbeat's interval is in seconds, from 60 to 604800 (1 minute to 7 days), and AutoHeal reports a missed heartbeat only when the heartbeat states its interval. The status is `up`, `degraded` or `down`. Input AutoHeal would refuse is ignored, not thrown.

### Testing the connection

```bash
php artisan autoheal:test
```

This sends a test exception straight away and says whether AutoHeal accepted it, refused the key, or could not be reached. It exits with an error unless the event was accepted.

## Delivery

- At most 50 events are kept for one request, job or command. Further events are dropped, and the number dropped is sent with the last event.
- Events are sent once per request, job or command:
  - after the response has been sent (`terminating`), which under PHP-FPM is after the user has the response;
  - after each queued job, including failed ones;
  - after each console command;
  - after each Octane request, when Octane is installed.
- Each send waits at most 1 second to connect and 2 seconds in total.
- `php artisan serve` runs the after-response work before it closes the connection, so in local development only, an unreachable AutoHeal delays one response by up to the connect timeout before sending pauses.

### Queued delivery

With `AUTOHEAL_TRANSPORT=queue`, the events of a request, job or command are handed to an `AutoHeal\Laravel\Jobs\SendEvents` job on the application's default queue connection. The job tries 3 times, 10 and 60 seconds apart, with the same event identifiers each time, so AutoHeal stores each event once. A failed attempt releases the job instead of throwing, so AutoHeal outages never fill the application's failed jobs.

## What is never sent

- Request bodies, headers, cookies, query strings and session data.
- User identifiers.
- Values under keys containing `password`, `passwd`, `secret`, `token`, `api_key`, `apikey`, `authorization`, `cookie`, `session`, `card_number`, `cvv`, `private_key` or `access_key` (case-insensitive, `-` and `_` ignored) become `[redacted]`.
- Values that look like secrets become `[redacted]` wherever they appear: bearer tokens, JWTs, private key blocks, AutoHeal secrets, AWS access key IDs, passwords in URLs (`scheme://user:password@host`) and `Password=` or `pwd=` in connection strings.
- Objects in log context are sent as their class name only, never their contents.

Stack frame paths, line numbers, functions and classes are kept as they are, so the stack trace stays readable. File paths are relative to the application's base path.

The AutoHeal platform applies the same redaction again before storing anything. `tests/Fixtures/redaction-cases.json` is shared with the platform, and both test suites run every case.

## When AutoHeal fails

- No failure to reach AutoHeal, or to be accepted by it, throws into the application or changes its responses or exit codes.
- After a failed send, a refused key (401 or 403) or any other error answer, the SDK stops sending for 60 seconds, or for a longer `Retry-After`. The pause is kept in the process and in the application cache, so other requests and workers skip sending too.
- Each pause logs one warning on the application's log. The SDK never reports its own warning.
- Events captured during a pause are dropped.

## Sample application

The Testbench workbench is a small Laravel application with a route, `/autoheal/sample-exception`, that throws a `RuntimeException` on purpose.

```bash
cp workbench/.env.example workbench/.env   # then set AUTOHEAL_KEY
composer serve
```

`workbench/.env` is git-ignored. Open `/autoheal/sample-exception`: the exception appears on the environment's events page. Point `AUTOHEAL_ENDPOINT` at an address that does not answer, and the route answers exactly as before.

## Development

```bash
composer install
composer check   # Pint, PHPStan and Pest
```
