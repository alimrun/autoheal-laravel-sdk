# AutoHeal Laravel SDK

Reports a Laravel application's exceptions, error logs, deployments, heartbeats and health checks to AutoHeal. It also offers a cache hook the AutoHeal agent can call during recovery. It is built so that AutoHeal can never slow the application down, break it or leak its secrets.

## Requirements

PHP 8.3 or later, and Laravel 11, 12 or 13.

## Install

```bash
composer require autoheal/laravel-sdk
```

## Quick start

Set `AUTOHEAL_ENDPOINT` and `AUTOHEAL_KEY` in `.env`. The key comes from the environment's page in the AutoHeal dashboard. Without both, the SDK does nothing.

```dotenv
AUTOHEAL_ENDPOINT=https://autoheal.example.com/api/v1
AUTOHEAL_KEY=<your SDK key>
```

Check the connection:

```bash
php artisan autoheal:test
```

Exceptions and error logs now reach AutoHeal. Deployments, heartbeats and health checks are shown below and in [Events and commands](docs/events.md).

## Documentation

- [Configuration](docs/configuration.md): every key, its environment variable and default.
- [Events and commands](docs/events.md): what is reported, the artisan commands and the facade.
- [Cache hook](docs/cache-hook.md): let the agent clear a cache during recovery.
- [Delivery](docs/delivery.md): when events are sent, queued delivery, outages and what is never sent.
- [Troubleshooting](docs/troubleshooting.md): command messages and common problems.
- [Development](docs/development.md): tests, Pint, PHPStan and the sample application.
- [Changelog](CHANGELOG.md)

<details>
<summary>Optional variables, heartbeats, deployments and health checks</summary>

Optional variables: `AUTOHEAL_RELEASE`, `AUTOHEAL_SERVICE`, `AUTOHEAL_TRANSPORT` (`http` or `queue`), `AUTOHEAL_LOG_LEVEL` (default `error`) and `AUTOHEAL_ENABLED`. To change other defaults, publish the configuration:

```bash
php artisan vendor:publish --tag=autoheal-config
```

Send a heartbeat from the scheduler:

```php
// routes/console.php
Schedule::command('autoheal:heartbeat scheduler --every=60')->everyMinute();
```

Report deployments and health checks from a pipeline or your own checks:

```bash
php artisan autoheal:deploy 2026.11.06-1 --source=github-actions
php artisan autoheal:health database degraded --latency=840
```

</details>

## License

MIT. See [LICENSE](LICENSE).
