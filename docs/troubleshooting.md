# Troubleshooting

## Testing the connection

```bash
php artisan autoheal:test
```

This sends a test exception straight away and says whether AutoHeal accepted it, refused the key, rejected the event, or could not be reached. It exits with an error unless the event was accepted. On success it prints the event's ID, and the exception appears on the environment's events page.

`autoheal:test` sends even while sending is paused, and a failure does not start a pause. Run it again after each fix.

## Command messages

These are the messages `autoheal:test`, `autoheal:deploy`, `autoheal:heartbeat` and `autoheal:health` print for AutoHeal's answer.

| Message | Meaning | What to do |
|---|---|---|
| `AutoHeal accepted the event.` | The event was stored. | Nothing. |
| `AutoHeal accepted the test event <event ID>.` | `autoheal:test` worked. | Nothing. |
| `AutoHeal already had this event and stored it once.` | The same event was sent before. | Nothing. |
| `AutoHeal is not configured: set AUTOHEAL_KEY and AUTOHEAL_ENDPOINT.` | The key or the endpoint is missing or empty, or `AUTOHEAL_ENABLED` is `false`. | Set both in `.env`. If the configuration is cached, run `php artisan config:cache`. |
| `AutoHeal refused the SDK key: it is unknown or revoked (...)` | AutoHeal answered 401. | Copy the key again from the environment's page in the AutoHeal dashboard, or create a new one there. |
| `AutoHeal refused the SDK key: its environment or organization cannot receive events right now (...)` | AutoHeal answered 403. | Check the environment and the organization in the AutoHeal dashboard. |
| `AutoHeal rejected the event (...)` | AutoHeal answered with another error, such as 404, 429 or 500. | Check that `AUTOHEAL_ENDPOINT` is the API base, such as `https://autoheal.example.com/api/v1`, without `/telemetry/events`. For 429, wait. Otherwise check the platform. |
| `AutoHeal could not be reached at the configured endpoint.` | No answer within 1 second to connect or 2 seconds in total. | Check the endpoint's host, port and scheme, DNS, and the firewall from this server. |
| `Sending is paused after a failed send. Try again in a minute.` | An earlier send failed, and sending is paused. | See [Sending is paused](#sending-is-paused). `autoheal:test` is not affected. |

The text in brackets is AutoHeal's answer code, such as `sdk_key_unauthenticated_401`, or the HTTP status when there is none.

`autoheal:deploy`, `autoheal:heartbeat` and `autoheal:health` print these as warnings and still exit successfully. They exit with an error only for input AutoHeal would refuse:

| Message | What to do |
|---|---|
| `The release must be 1 to 100 characters.` | Shorten the release. |
| `The name must be 1 to 100 letters, digits, ".", "_", ":" or "-".` | Remove spaces and other characters from the name. |
| `The interval must be a whole number of seconds from 60 to 604800 (1 minute to 7 days).` | Fix `--every`. |
| `The status must be up, degraded or down.` | Fix the status. |
| `The latency must be a whole number of milliseconds.` | Fix `--latency`. |

## Sending is paused

After a failed send, the SDK stops sending for 60 seconds, or longer if AutoHeal sent a `Retry-After`. It logs one warning on the application's log:

| Warning | Meaning |
|---|---|
| `AutoHeal paused sending for 60 seconds: AutoHeal could not be reached.` | No answer in time. |
| `AutoHeal paused sending for 60 seconds: AutoHeal refused the SDK key (HTTP 401).` | The key was refused, with 401 or 403. |
| `AutoHeal paused sending for 60 seconds: AutoHeal rejected the events (HTTP 429).` | Another error answer. |
| `AutoHeal paused sending for 60 seconds: the SDK failed with <class>.` | The SDK itself failed while building or sending events. |

The warning's context carries the HTTP status and AutoHeal's answer code when there are any.

Events captured during a pause are dropped. Sending starts again by itself when the pause runs out. A successful `autoheal:test` does not end the pause.

To end a pause sooner, after fixing its cause, remove it from the application cache:

```bash
php artisan cache:forget autoheal:paused_until
```

Long-running processes, such as queue workers and Octane, also keep the pause in memory until it runs out or they restart.

## Nothing reaches AutoHeal

Check these in order:

1. `php artisan autoheal:test` succeeds. If not, see [Command messages](#command-messages).
2. The configuration is current. If you cache it, run `php artisan config:cache` after changing `.env`.
3. The exception is reported. Exceptions in the application's `dontReport` list, and exceptions it handles without calling `report()`, are not sent.
4. The log line is at `AUTOHEAL_LOG_LEVEL` or above. The default is `error`.
5. Sending is not paused. Look for `AutoHeal paused sending` in the application's log.
6. With `AUTOHEAL_TRANSPORT=queue`, a queue worker is running on the application's default queue connection.
7. A heartbeat, health check or deployment reported from code is sent only when the request, job or command ends, or when you call `AutoHeal::flush()`.

## Some events are missing

At most 50 events are kept for one request, job or command. The rest are dropped, and the number dropped is sent with the last event as `sdk.dropped_events`. An event larger than 64000 bytes is shrunk, so its log context or some stack frames may be left out.

## A secret was not redacted

Add the key's name to `redact_keys` in `config/autoheal.php` (see [Configuration](configuration.md#redact_keys)). Keep the default names in the list. The platform redacts again before storing.

## Cache hook

| Answer | Cause | What to do |
|---|---|---|
| `404` with `{"message": "Not found."}` | The hook is turned off. | Set `AUTOHEAL_CACHE_HOOK_ENABLED=true`, and check that `AUTOHEAL_ENABLED` is not `false`. |
| `404` from Laravel | The route is not registered, or the cache name is not valid. | Check that the token has at least 32 characters. If routes are cached, run `php artisan route:cache` again. Check `AUTOHEAL_CACHE_HOOK_PATH` and the agent's `url`. |
| `404` with `{"message": "The cache is not listed."}` | The name in the URL is not in `cache_hook.caches`. | Use the same name in the agent and in `config/autoheal.php`. |
| `403` | The request did not come from loopback, or carried a forwarding header. | Point the agent at `127.0.0.1` or `::1`, on a server block that does not add forwarding headers. See [Reaching the application on loopback](cache-hook.md#reaching-the-application-on-loopback). |
| `401` | The token is missing or wrong. | Put the same token in `AUTOHEAL_CACHE_HOOK_TOKEN` and in the agent's `token_file`. |
| `500` with `{"message": "The cache is misconfigured."}` | The entry's kind is not `views` or `store`, or its store is not in `cache.stores`. The application's log has one warning naming the problem. | Fix the entry in `config/autoheal.php`. |
| `500` with `{"message": "The cache could not be cleared."}` | Clearing failed. | Check the cache store or the compiled views folder. |
| `422` | The body is not exactly `{"task_id": "<26 letters or digits>"}`. | Use the agent, which sends this body. |
| `503` | The application is in maintenance mode. | Bring it up with `php artisan up`. |
| A redirect | A global middleware redirects to HTTPS. | Use an `https` hook URL, or exempt the hook's path. |

## The sample application

To see the SDK end to end without your own application, run the sample application described in [Development](development.md#sample-application).
