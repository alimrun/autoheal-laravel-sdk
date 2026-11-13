# Delivery

The SDK keeps the events of one request, job or command in memory and sends them together when it ends. It never sends while the application is still answering, and no failure to send reaches the application.

## When events are sent

Events are sent once per request, job or command:

- after the response has been sent (`terminating`), which under PHP-FPM is after the user has the response;
- after each queued job, including failed ones. A failed job's exception is sent when the worker moves on to the next job;
- after each console command, when the outermost command finishes;
- after each Octane request, when Octane is installed.

Jobs on the `sync` queue connection run inside a request or command, so their events are sent with that request or command.

`php artisan serve` runs the after-response work before it closes the connection. So in local development only, an unreachable AutoHeal delays one response by up to the connect timeout, before sending pauses.

The artisan commands do not wait: they send their one event straight away. See [Events and commands](events.md#artisan-commands).

## Buffer

At most 50 events are kept for one request, job or command. Further events are dropped, and the number dropped is sent with the last event, in its context as `sdk.dropped_events`. An error storm cannot grow memory without bound.

## Sending over HTTP

With `AUTOHEAL_TRANSPORT=http`, the default, the events are sent in one `POST` to `<AUTOHEAL_ENDPOINT>/telemetry/events`, with the SDK key as a bearer token.

Each send waits at most 1 second to connect and 2 seconds in total. A send that has not finished by then counts as unreachable.

## Queued delivery

With `AUTOHEAL_TRANSPORT=queue`, the events of a request, job or command are handed to an `AutoHeal\Laravel\Jobs\SendEvents` job on the application's default queue connection and queue. A queue worker must be running for them to be sent.

- The job sends over HTTP, with the same timeouts.
- It tries 3 times: the second attempt runs 10 seconds after the first, the third 60 seconds after the second.
- Every attempt sends the same event identifiers, so AutoHeal stores each event once even when an answer was lost.
- A failed attempt releases the job instead of throwing, so AutoHeal outages never fill the application's failed jobs. After the third attempt the job ends quietly and the events are dropped.
- While sending is paused, an attempt does not send and the job is released for its next attempt.

The artisan commands send over HTTP even when the transport is `queue`.

## When AutoHeal fails

- No failure to reach AutoHeal, or to be accepted by it, throws into the application or changes its responses or exit codes. The same holds for a failure inside the SDK itself.
- After a send that could not reach AutoHeal, a refused key (401 or 403) or any other error answer, the SDK stops sending for 60 seconds. If an error answer other than 401 or 403 carries a longer `Retry-After` in seconds, the pause lasts that long, up to 3600 seconds (1 hour). A failure inside the SDK pauses it the same way.
- The pause is kept in the process and in the application's default cache, under the key `autoheal:paused_until`. Other requests and workers skip sending too. If the cache itself fails, the pause is kept in the process only.
- Each pause logs one warning on the application's log, such as `AutoHeal paused sending for 60 seconds: AutoHeal could not be reached.` The SDK never reports its own warning.
- Events captured during a pause are dropped. With queued delivery, events captured during a pause are not queued.
- Sending starts again by itself when the pause runs out.

An AutoHeal outage costs the application one connect timeout per pause, not one per request.

`autoheal:test` ignores a pause and does not start one, so you can use it to check the connection after fixing it. A successful test does not end a running pause. See [Troubleshooting](troubleshooting.md#sending-is-paused).

## What is never sent

- Request bodies, headers, cookies, query strings and session data.
- User identifiers.
- Organization, project or environment identifiers. The key alone decides where an event is stored.
- Values under keys containing `password`, `passwd`, `secret`, `token`, `api_key`, `apikey`, `authorization`, `cookie`, `session`, `card_number`, `cvv`, `private_key` or `access_key` (case-insensitive, `-` and `_` ignored) become `[redacted]`. You can change this list with `redact_keys` (see [Configuration](configuration.md#redact_keys)).
- Values that look like secrets become `[redacted]` wherever they appear outside stack frames, such as in messages, previous exceptions and log context: bearer tokens, JWTs, private key blocks, AutoHeal secrets, AWS access key IDs, passwords in URLs (`scheme://user:password@host`) and `Password=` or `pwd=` in connection strings.
- Objects in log context are sent as their class name only, never their contents.

Stack frame paths, line numbers, functions and classes are kept as they are, so the stack trace stays readable. Paths inside the application's base path are sent relative to it; paths outside it, such as PHP's own, stay as they are.

Secrets are redacted before long strings are cut, so a cut can never hide the end of a secret from the patterns.

The AutoHeal platform applies the same redaction again before storing anything. `tests/Fixtures/redaction-cases.json` is shared with the platform, and both test suites run every case.
