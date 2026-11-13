# Cache hook

From agent 0.2.0, the AutoHeal agent can run a `clear_cache` recovery action: AutoHeal sends it a signed task, and the agent sends one `POST` to a cache hook on the same host. The SDK has a ready-made hook, so the application needs no code.

The hook is off unless you turn it on, it answers only requests from the same host that carry its token, and it can clear only the caches you list.

## Set it up

### 1. Generate a token

Generate a token once, and keep it out of source control:

```bash
openssl rand -hex 32
```

The token must be at least 32 characters. `openssl rand -hex 32` prints 64.

### 2. Turn the hook on

In the application's `.env`:

```dotenv
AUTOHEAL_CACHE_HOOK_ENABLED=true
AUTOHEAL_CACHE_HOOK_TOKEN=<the token>
```

The hook is registered only when `AUTOHEAL_CACHE_HOOK_ENABLED` is `true`, the token has at least 32 characters and `AUTOHEAL_ENABLED` is not `false`. It does not need `AUTOHEAL_KEY` or `AUTOHEAL_ENDPOINT`, because it never calls AutoHeal.

By default the hook answers at `POST /autoheal/cache/<cache>`. Set `AUTOHEAL_CACHE_HOOK_PATH` to use another prefix. The route is named `autoheal.cache-hook`.

### 3. List the caches

The hook can clear only the caches listed under `cache_hook.caches` in `config/autoheal.php`. Only compiled views are listed by default. To list more, publish the configuration first:

```bash
php artisan vendor:publish --tag=autoheal-config
```

Then edit the list:

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

### 4. Cache the routes again

If the application caches its routes, run `php artisan route:cache` again after turning the hook on or changing its path. The SDK adds the hook's route only while the route table is not cached, so a cached table holds the hook only if it was on when the table was built.

Turning the hook off takes effect without this step: a route that is still in the cached table refuses every request with `404`. If you cache the configuration, run `php artisan config:cache` after any change to `.env`.

### 5. Configure the agent

Put the same token in a file only the agent can read:

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

Recovery also has to be turned on in the agent and in AutoHeal. The agent's documentation describes this on its Recovery page.

## What the hook accepts

The agent's side is described in the agent's documentation, on its Recovery page, under "The cache hook contract". The hook checks each request in this order:

| Check | Answer when it fails |
|---|---|
| The hook is still on | `404` |
| The connection comes from `127.0.0.0/8` or `::1`, with no `Forwarded`, `X-Forwarded-For`, `X-Forwarded-Host` or `X-Real-IP` header | `403` |
| `Authorization: Bearer <token>` matches the configured token | `401` |
| The cache is listed | `404` |
| The cache's entry is valid: a known kind, and a store that exists | `500`, and one warning in the application's log |
| The body is exactly `{"task_id": "<26 letters or digits>"}` | `422` |
| The cache was cleared | `204` with no body, or `500` if it could not be cleared |

- **Refusals** answer with a JSON body, such as `{"message": "The cache is not listed."}`.
- **Names that are not valid**, such as `.views` or a name longer than 100 characters, do not match the route at all. Laravel answers `404` before any check runs.
- **Other methods** than `POST` do not clear anything.
- **Peer address:** the hook reads the address of the connection itself, not `X-Forwarded-For`, even when the application trusts proxies. IPv4 addresses mapped into IPv6, such as `::ffff:127.0.0.1`, count as loopback.
- **Body:** the body may hold only `task_id`. Any other key, such as a cache or command name, is refused with `422`.
- **Repeats:** clearing is safe to repeat.

## Security

- **Off by default.** Nothing is registered until you turn the hook on with a token.
- **Loopback only.** Only connections from this host are accepted, and any forwarding header is refused, because the agent never sends one.
- **Token.** The token is compared in constant time. It is never logged or returned in an answer.
- **Fixed actions.** The configuration chooses a kind and a store name, never a command or a class. The request only picks a listed name.
- **No session.** The hook's route is outside the `web` middleware group, so it has no session, cookies or CSRF check. Global middleware still applies.
- **Logging.** Each clear writes one `info` line, `AutoHeal cache hook cleared a cache.`, with the cache name and the task ID. A misconfigured entry writes one warning. Other refusals are not logged.
- **AutoHeal.** The hook itself sends nothing to AutoHeal. AutoHeal judges the outcome from the agent's health check. If you set `AUTOHEAL_LOG_LEVEL` to `info` or lower, the SDK reports the hook's log line like any other log line at that level.

## Reaching the application on loopback

The hook URL must reach the application on this host:

- **nginx:** use a server that answers on `127.0.0.1`. Either a `default_server`, or a dedicated loopback-only block such as `listen 127.0.0.1:8081;` that serves the same application. Then use `http://127.0.0.1:8081/autoheal/cache/views` in the agent.
- **Octane, or `php artisan serve`:** use the port it listens on.
- **Public proxy:** if a public reverse proxy on the same host sends requests without any forwarding header, block `/autoheal/cache/` there. Otherwise public requests would look local, and only the token would stop them.
- **HTTPS redirect:** a global middleware that redirects to HTTPS makes the agent report the action as failed, because the agent never follows a redirect. Use an `https` hook URL with a certificate the agent trusts (it always verifies certificates; see `ca_file` in the agent's documentation), or exempt the hook's path.
- **Maintenance mode:** while the application is down (`php artisan down`), the hook answers `503`, and the agent reports the action as failed.

## Checking the hook by hand

From the same host, with the token in a shell variable:

```bash
curl -i -X POST http://127.0.0.1/autoheal/cache/views \
  -H "Authorization: Bearer $AUTOHEAL_CACHE_HOOK_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"task_id": "01JCX3V6Q2N5W8Y0B4D7F9H1KM"}'
```

A `204` means the views were cleared. Any other answer is listed in [What the hook accepts](#what-the-hook-accepts) and [Troubleshooting](troubleshooting.md#cache-hook).
