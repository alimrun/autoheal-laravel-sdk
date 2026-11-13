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
| `AUTOHEAL_ENABLED` | `true` | Set to `false` to turn the SDK off, for example in the application's own test suite. This also turns the cache hook off. |
| `AUTOHEAL_CACHE_HOOK_ENABLED` | `false` | Turns on the [cache hook](#cache-hook-for-agent-recovery) for the agent's `clear_cache` action. |
| `AUTOHEAL_CACHE_HOOK_TOKEN` | none | The hook's bearer token, at least 32 characters. Without it the hook is not registered. |
| `AUTOHEAL_CACHE_HOOK_PATH` | `autoheal/cache` | The hook's path prefix. The hook answers at `POST /<prefix>/<cache>`. |

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

### Cache hook for agent recovery

From agent 0.2.0, the AutoHeal agent can run `clear_cache`: AutoHeal sends it a signed task, and the agent sends one `POST` to a cache hook on the same host. The SDK has a ready-made hook, so no code is needed. It is off unless you turn it on, and it can clear only the caches you list.

1. Generate a token once, and keep it out of source control:

   ```bash
   openssl rand -hex 32
   ```

2. In the application's `.env`:

   ```dotenv
   AUTOHEAL_CACHE_HOOK_ENABLED=true
   AUTOHEAL_CACHE_HOOK_TOKEN=<the token>
   ```

3. List the caches the hook may clear, in `config/autoheal.php` (publish it first with `php artisan vendor:publish --tag=autoheal-config`):

   ```php
   'cache_hook' => [
       // ...
       'caches' => [
           'views' => ['kind' => 'views'],
           'application' => ['kind' => 'store', 'store' => 'redis'],
       ],
   ],
   ```

   There are two kinds:
   - `views` clears the compiled views, as `php artisan view:clear` does.
   - `store` flushes one store from `config/cache.php`'s `stores`.

   **Flushing a Redis store empties its whole Redis database.** If sessions, queues or anything else share that database, they are wiped too. Give the cache its own Redis connection or database before listing it.

   The hook's address picks one entry from this list. A request cannot name a command, a file or any cache that is not listed. A name is 1 to 100 letters, digits, `.`, `_` or `-`, starting with a letter or digit.

4. If the application caches its routes, run `php artisan route:cache` again.

5. Put the same token in a file only the agent can read:

   ```bash
   sudo install -d -m 0750 -o root -g autoheal-agent /etc/autoheal/hooks
   sudo install -m 0600 -o autoheal-agent -g autoheal-agent /dev/null /etc/autoheal/hooks/views.token
   sudoedit /etc/autoheal/hooks/views.token
   ```

   Then add one hook per cache to the agent's configuration, using the same name, and restart the agent:

   ```yaml
   recovery:
     actions:
       clear_cache:
         hooks:
           - name: views
             url: "http://127.0.0.1/autoheal/cache/views"
             token_file: /etc/autoheal/hooks/views.token
             timeout: 30s
   ```

#### What the hook accepts

The agent's side is described in the agent README, under "The cache hook contract". The hook checks each request in this order:

| Check | Answer when it fails |
|---|---|
| The hook is still on | `404` |
| The connection comes from `127.0.0.0/8` or `::1`, with no `Forwarded`, `X-Forwarded-For`, `X-Forwarded-Host` or `X-Real-IP` header | `403` |
| `Authorization: Bearer <token>` matches the configured token | `401` |
| The cache is listed | `404` |
| The cache's entry is valid: a known kind, and a store that exists | `500`, and one warning in the application's log |
| The body is exactly `{"task_id": "<26 letters or digits>"}` | `422` |
| The cache was cleared | `204` with no body, or `500` if it could not be cleared |

- **Peer address:** the hook reads the address of the connection itself, not `X-Forwarded-For`, even when the application trusts proxies.
- **Repeats:** clearing is safe to repeat.
- **Logging:** each clear writes one `info` line with the cache name and the task ID. Refusals are not logged.
- **Secrets:** the token is never logged or returned.
- **AutoHeal:** the hook sends nothing to AutoHeal. AutoHeal judges the outcome from the agent's health check.

#### Reaching the application on loopback

The hook URL must reach the application on this host:

- **nginx:** use a server that answers on `127.0.0.1`. Either a `default_server`, or a dedicated loopback-only block such as `listen 127.0.0.1:8081;` that serves the same application. Then use `http://127.0.0.1:8081/autoheal/cache/views` in the agent.
- **Octane, or `php artisan serve`:** use the port it listens on.
- **Public proxy:** if a public reverse proxy on the same host sends requests without any forwarding header, block `/autoheal/cache/` there. Otherwise public requests would look local, and only the token would stop them.
- **HTTPS redirect:** a global middleware that redirects to HTTPS makes the agent report the action as failed, because the agent never follows a redirect. Use an `https` hook URL with the agent's `ca_file`, or exempt the hook's path.
- **Maintenance mode:** while the application is down (`php artisan down`), the hook answers `503`, and the agent reports the action as failed.

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
