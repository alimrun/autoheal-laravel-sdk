# Events and commands

Once the SDK is on (see [Configuration](configuration.md#when-the-sdk-is-on)), it reports exceptions and error logs by itself. Deployments, heartbeats and health checks are reported when you ask, from the command line or from code.

## What is reported automatically

### Exceptions

Every exception that goes through Laravel's exception handler is reported, except those the application lists as not to be reported (`dontReport`). Each event carries:

- the class, the message and the code;
- up to 100 stack frames, innermost first, each marked as the application's own (`in_app`) or not. Frames in `vendor/` or outside the application's base path are not the application's own. The number of frames left out is sent too;
- up to 3 previous exceptions, with their class and message.

The event's severity is `error`. An exception with an empty message uses its class name as the message.

Laravel also writes a log line for each reported exception. That line is not sent again as a log.

### Error logs

Log lines at `AUTOHEAL_LOG_LEVEL` or above (`error` by default) are reported, unless they carry an exception in their `exception` context key. Each event carries the level, the application's default log channel (`logging.default`) and the log context.

The event's severity follows the level:

| Log level | Severity |
|---|---|
| `debug` | `debug` |
| `info`, `notice` | `info` |
| `warning` | `warning` |
| `error` | `error` |
| `critical`, `alert`, `emergency` | `critical` |

In the log context, objects are sent as their class name only, such as `[object App\Models\Order]`, never their contents. Nesting deeper than 5 levels becomes `[nested too deep]`.

The SDK's own warnings, such as the one it writes when it pauses sending, are never reported.

### Context

Every event carries a little context about where it happened:

- the application environment, and the PHP and Laravel versions;
- for a request: its method, its path without the query string, and its route name when it has one;
- for a queued job: its class and its queue;
- for a console command: the name of the outermost command.

The release and service from `AUTOHEAL_RELEASE` and `AUTOHEAL_SERVICE` are added when set. Headers, cookies, bodies, query strings, the session and the user are never read. See [What is never sent](delivery.md#what-is-never-sent).

## Deployments, heartbeats and health checks

| Event | Use it to | Severity |
|---|---|---|
| Deployment | mark when a release went out | `info` |
| Heartbeat | say that something is still running, such as the scheduler or a nightly import | `info` |
| Health check | report the result of a check you run, such as a database ping | `info` for `up`, `warning` for `degraded`, `error` for `down` |

### Names, intervals and statuses

- A heartbeat or health check name is 1 to 100 letters, digits, `.`, `_`, `:` or `-`. Spaces are not allowed.
- A heartbeat's interval is in seconds, from 60 to 604800 (1 minute to 7 days). AutoHeal reports a missed heartbeat only when the heartbeat states its interval.
- A health check's status is `up`, `degraded` or `down`.
- A health check's latency is in milliseconds. Values above 600000 (10 minutes) are sent as 600000, and negative values as 0.
- A deployment's release is 1 to 100 characters. Its source is cut to 100 characters.

### Deployments

A deployment event carries the release it reports as the event's release, even when `AUTOHEAL_RELEASE` is set to something else.

## Artisan commands

The SDK adds four commands. They send one event straight away, over HTTP, whatever `AUTOHEAL_TRANSPORT` is set to, and print what AutoHeal answered. The messages are listed in [Troubleshooting](troubleshooting.md#command-messages).

### `autoheal:test`

```bash
php artisan autoheal:test
```

Sends a test exception, `AutoHeal test exception from autoheal:test`, and says whether AutoHeal accepted it. It has no arguments or options.

- It exits successfully only when AutoHeal accepted the event, and prints the event's ID.
- It exits with an error when the SDK is not configured, the key was refused, the event was rejected, or AutoHeal could not be reached.
- It sends even while sending is paused, and a failure does not start a pause, so you can run it again right after fixing the connection.

### `autoheal:deploy`

```bash
php artisan autoheal:deploy 2026.11.06-1 --source=github-actions
```

| Argument or option | Required | Meaning |
|---|---|---|
| `release` | yes | The release that was deployed, 1 to 100 characters. |
| `--source=` | no | Where the deployment came from, such as `github-actions` or `forge`. |

### `autoheal:heartbeat`

```bash
php artisan autoheal:heartbeat scheduler --every=60
```

| Argument or option | Required | Meaning |
|---|---|---|
| `name` | yes | The heartbeat's name. |
| `--every=` | no | How often the heartbeat is expected, in whole seconds, from 60 to 604800. |

### `autoheal:health`

```bash
php artisan autoheal:health database degraded --latency=840
```

| Argument or option | Required | Meaning |
|---|---|---|
| `name` | yes | The health check's name. |
| `status` | yes | `up`, `degraded` or `down`. |
| `--latency=` | no | How long the check took, in whole milliseconds. |

### Exit codes

`autoheal:deploy`, `autoheal:heartbeat` and `autoheal:health` always exit successfully, whatever AutoHeal answers, and also when the SDK is not configured. An AutoHeal outage never fails a deploy or the scheduler. They exit with an error only for input AutoHeal would refuse, such as a name with spaces, and then send nothing.

## Scheduling

Send a heartbeat from the scheduler, so AutoHeal notices when the scheduler stops:

```php
// routes/console.php
Schedule::command('autoheal:heartbeat scheduler --every=60')->everyMinute();
```

State the interval with `--every`, and keep it in step with how often the command runs. Without an interval, AutoHeal does not report a missed heartbeat.

The same works for other scheduled work. For example, a heartbeat after each successful nightly import:

```php
use AutoHeal\Laravel\Facades\AutoHeal;

Schedule::command('orders:import')
    ->dailyAt('02:00')
    ->onSuccess(fn () => AutoHeal::heartbeat('nightly-import', every: 86400));
```

## From code

The `AutoHeal` facade reports the same events from code:

```php
use AutoHeal\Laravel\Facades\AutoHeal;

AutoHeal::deployment('2026.11.06-1', 'forge');
AutoHeal::heartbeat('nightly-import', every: 86400);
AutoHeal::healthCheck('database', 'up', latencyMs: 12);
```

Unlike the commands, these calls do not send straight away. The events are kept with the rest and sent when the request, job or command ends, through the configured transport (see [Delivery](delivery.md)). Input AutoHeal would refuse is ignored, not thrown.

| Method | Meaning |
|---|---|
| `deployment(string $release, ?string $source = null)` | Reports a deployment. |
| `heartbeat(string $name, ?int $every = null)` | Reports a heartbeat, with its interval in seconds. |
| `healthCheck(string $name, string $status, ?int $latencyMs = null)` | Reports a health check result. |
| `captureException(Throwable $exception)` | Reports an exception you caught and did not pass to `report()`. An exception passed to `report()` is reported already, so do not call both. |
| `captureLog(string $level, string $message, array $context = [], ?string $channel = null)` | Reports a log line directly. `AUTOHEAL_LOG_LEVEL` does not apply. The level must be one of the eight log levels, or the call is ignored. |
| `flush()` | Sends what has been kept so far, now, instead of when the request, job or command ends. |
| `isEnabled()` | Whether the SDK is on. |

## Limits on every event

The SDK applies the platform's limits before sending, so AutoHeal does not refuse an event for its size:

- The message is cut to 2000 characters.
- Strings in the context and body are cut to 8000 bytes.
- An event larger than 64000 bytes is shrunk: first the log context is left out, then stack frames from the outer end, then the message is cut to 200 characters and the context is reduced to the environment and versions.
