# Configuration

The SDK reads its settings from `config/autoheal.php`. Most of them come from environment variables, so for most applications the `.env` file is enough.

To change a setting that has no environment variable, such as the redacted keys or the caches the cache hook may clear, publish the configuration file:

```bash
php artisan vendor:publish --tag=autoheal-config
```

This copies the package's file to `config/autoheal.php`. Top-level keys you leave out of your copy fall back to the package's defaults. A top-level key you keep, such as `cache_hook` or `redact_keys`, replaces the package's value as a whole.

## When the SDK is on

The SDK reports only when all three are true:

- `enabled` is `true`;
- `key` is set and not empty;
- `endpoint` is set and not empty.

Otherwise it registers nothing, captures nothing and sends nothing, and the application behaves as if the SDK were not installed. The artisan commands are still registered: `autoheal:test` says `AutoHeal is not configured: set AUTOHEAL_KEY and AUTOHEAL_ENDPOINT.` and fails.

The SDK decides at boot whether it captures exceptions and logs. If you cache the configuration with `php artisan config:cache`, run it again after changing `.env`.

## Keys

| Key | Environment variable | Type | Default |
|---|---|---|---|
| `enabled` | `AUTOHEAL_ENABLED` | boolean | `true` |
| `key` | `AUTOHEAL_KEY` | string | none |
| `endpoint` | `AUTOHEAL_ENDPOINT` | string | none |
| `release` | `AUTOHEAL_RELEASE` | string | none |
| `service` | `AUTOHEAL_SERVICE` | string | none |
| `transport` | `AUTOHEAL_TRANSPORT` | `http` or `queue` | `http` |
| `log_level` | `AUTOHEAL_LOG_LEVEL` | log level | `error` |
| `redact_keys` | none | list of strings | see below |
| `cache_hook.enabled` | `AUTOHEAL_CACHE_HOOK_ENABLED` | boolean | `false` |
| `cache_hook.token` | `AUTOHEAL_CACHE_HOOK_TOKEN` | string | none |
| `cache_hook.path` | `AUTOHEAL_CACHE_HOOK_PATH` | string | `autoheal/cache` |
| `cache_hook.caches` | none | map of cache entries | `['views' => ['kind' => 'views']]` |

### `enabled`

The master switch. Set `AUTOHEAL_ENABLED=false` to turn the SDK off, for example in the application's own test suite. This also turns the [cache hook](cache-hook.md) off.

### `key`

The SDK key from the environment's page in the AutoHeal dashboard. It starts with `ahk_`. It is sent as a bearer token with every batch.

The key alone decides which organization, project and environment an event is stored in. The SDK never sends an organization, project or environment identifier.

Keep the key out of source control. Put it in `.env`, not in `config/autoheal.php`.

### `endpoint`

The platform API, such as `https://autoheal.example.com/api/v1`. The SDK sends events to `<endpoint>/telemetry/events`, so do not add that part yourself. A trailing `/` is ignored.

### `release`

The running release, such as a version or a commit. When set, every event carries it, cut to 100 characters. A deployment event carries the release it reports instead (see [Events and commands](events.md#deployments)).

### `service`

The name of this service, such as `checkout-api`. When set, every event carries it, cut to 100 characters.

### `transport`

How the events of a request, job or command are sent:

- `http` sends them in one batch after the response, job or command has finished.
- `queue` hands them to an `AutoHeal\Laravel\Jobs\SendEvents` job on the application's default queue connection.

Any other value is treated as `http`. The artisan commands always send over HTTP, whatever this is set to. See [Delivery](delivery.md).

### `log_level`

The lowest log level that is reported as an error log event. From lowest to highest: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. The value is not case-sensitive. An unknown value is treated as `error`.

Log lines that carry an exception are never reported as logs, because the exception is reported already. See [Events and commands](events.md#error-logs).

### `redact_keys`

Values under a key that contains any of these names are replaced with `[redacted]` before sending. Names are compared case-insensitively, with `-` and `_` ignored, so `password` also matches `user_password` and `X-Password`. The default list:

```php
'redact_keys' => [
    'password',
    'passwd',
    'secret',
    'token',
    'api_key',
    'apikey',
    'authorization',
    'cookie',
    'session',
    'card_number',
    'cvv',
    'private_key',
    'access_key',
],
```

The list in your published file replaces the default list, so keep the default names when you add your own. The patterns that redact secrets inside strings always apply and cannot be turned off. See [What is never sent](delivery.md#what-is-never-sent).

### `cache_hook.enabled`

Turns on the [cache hook](cache-hook.md) for the AutoHeal agent's `clear_cache` action. The hook is registered only when this is `true`, `enabled` is `true` and `cache_hook.token` has at least 32 characters. The hook does not need `key` or `endpoint`, because it never calls AutoHeal.

### `cache_hook.token`

The bearer token the agent must send. It must be at least 32 characters, or the hook is not registered. Generate it with `openssl rand -hex 32` and keep it out of source control.

### `cache_hook.path`

The hook's path prefix. The hook answers at `POST /<path>/<cache>`. Leading and trailing `/` are ignored, and an empty value falls back to `autoheal/cache`.

### `cache_hook.caches`

The caches the hook may clear, keyed by the name in the hook's address. Each entry has a `kind`:

- `['kind' => 'views']` clears the compiled views, as `php artisan view:clear` does.
- `['kind' => 'store', 'store' => '<name>']` flushes one store from `config/cache.php`'s `stores`.

Only `views` is listed by default. A name must be 1 to 100 letters, digits, `.`, `_` or `-`, starting with a letter or digit; an entry under any other name can never be reached. See [Cache hook](cache-hook.md#3-list-the-caches) before you list a Redis store.

## Fixed values

These are not configurable:

| Setting | Value |
|---|---|
| Events kept for one request, job or command | 50 |
| Connect timeout per send | 1 second |
| Total timeout per send | 2 seconds |
| Pause after a failed send | 60 seconds, or a longer `Retry-After` up to 3600 seconds |
| Queued job attempts | 3, released after 10 and then 60 seconds |
