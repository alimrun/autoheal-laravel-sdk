<?php

namespace AutoHeal\Laravel;

use AutoHeal\Laravel\CacheHook\CacheClearer;
use AutoHeal\Laravel\CacheHook\CacheHookController;
use AutoHeal\Laravel\CacheHook\RestrictToLocalAgent;
use AutoHeal\Laravel\Console\DeployCommand;
use AutoHeal\Laravel\Console\HealthCommand;
use AutoHeal\Laravel\Console\HeartbeatCommand;
use AutoHeal\Laravel\Console\TestCommand;
use AutoHeal\Laravel\Events\EventContext;
use AutoHeal\Laravel\Events\EventFactory;
use AutoHeal\Laravel\Events\StackFrames;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class AutoHealServiceProvider extends ServiceProvider
{
    private const OCTANE_REQUEST_TERMINATED = 'Laravel\Octane\Events\RequestTerminated';

    private const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/autoheal.php', 'autoheal');

        $this->app->singleton(AutoHeal::class);
        $this->app->singleton(Buffer::class);
        $this->app->singleton(CacheClearer::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(EventContext::class);
        $this->app->singleton(EventFactory::class);
        $this->app->singleton(
            Redactor::class,
            fn (Application $app): Redactor => new Redactor((array) $app->make('config')->get('autoheal.redact_keys', [])),
        );
        $this->app->singleton(StackFrames::class, fn (Application $app): StackFrames => new StackFrames($app->basePath()));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/autoheal.php' => $this->app->configPath('autoheal.php')], 'autoheal-config');

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class, DeployCommand::class, HeartbeatCommand::class, HealthCommand::class]);
        }

        $this->registerCacheHook();

        if (! $this->app->make(AutoHeal::class)->isEnabled()) {
            return;
        }

        $this->captureExceptions();
        $this->captureLogs();
        $this->trackContext();
        $this->flushWhenFinished();
    }

    /**
     * Outside the web group, so the hook has no session, cookies or CSRF check. A cached route
     * table already holds it, and the middleware still refuses it once the hook is turned off.
     */
    private function registerCacheHook(): void
    {
        if (! $this->app->make(AutoHeal::class)->cacheHookEnabled()
            || ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            return;
        }

        $path = trim((string) $this->app->make('config')->get('autoheal.cache_hook.path'), '/');

        $this->app->make(Router::class)
            ->post(($path === '' ? 'autoheal/cache' : $path) . '/{cache}', CacheHookController::class)
            ->where('cache', CacheHookController::CACHE_NAME_PATTERN)
            ->middleware(RestrictToLocalAgent::class)
            ->name('autoheal.cache-hook');
    }

    /**
     * Report callbacks run only for exceptions the application reports, so its dontReport list
     * holds, and returning nothing lets the application's own logging continue.
     */
    private function captureExceptions(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $exception): void {
                $this->app->make(AutoHeal::class)->captureException($exception);
            });
        });
    }

    /**
     * A message that carries an exception was already captured as that exception. The breaker's
     * own warning is skipped so a pause never reports itself.
     */
    private function captureLogs(): void
    {
        $this->events()->listen(MessageLogged::class, function (MessageLogged $logged): void {
            if ($this->app->make(CircuitBreaker::class)->isWarning()
                || ($logged->context['exception'] ?? null) instanceof Throwable
                || ! $this->meetsLogLevel((string) $logged->level)) {
                return;
            }

            $channel = $this->app->make('config')->get('logging.default');

            $this->app->make(AutoHeal::class)->captureLog(
                (string) $logged->level,
                (string) $logged->message,
                $logged->context,
                is_string($channel) ? $channel : null,
            );
        });
    }

    private function trackContext(): void
    {
        $context = fn (): EventContext => $this->app->make(EventContext::class);

        $this->events()->listen(RouteMatched::class, function (RouteMatched $matched) use ($context): void {
            $context()->enterRequest($matched->request);
        });

        $this->events()->listen(JobProcessing::class, function (JobProcessing $processing) use ($context): void {
            $context()->enterJob($processing->job->resolveName(), $processing->job->getQueue());
        });

        $this->events()->listen(CommandStarting::class, function (CommandStarting $starting) use ($context): void {
            $context()->enterCommand($starting->command);
        });
    }

    /**
     * A worker reports a failed job's exception after the failing event, so the job context stays
     * until the worker loops, and the loop flushes what the report captured. Jobs on the sync
     * connection run inside a request or command, which flushes them when it ends.
     */
    private function flushWhenFinished(): void
    {
        $context = fn (): EventContext => $this->app->make(EventContext::class);
        $flush = fn () => $this->app->make(AutoHeal::class)->flush();

        $this->app->terminating($flush);

        Queue::after(function (JobProcessed $processed) use ($context, $flush): void {
            if ($processed->connectionName !== 'sync') {
                $flush();
            }

            $context()->leaveJob();
        });

        Queue::failing(function (JobFailed $failed) use ($flush): void {
            if ($failed->connectionName !== 'sync') {
                $flush();
            }
        });

        Queue::looping(function () use ($context, $flush): void {
            $flush();
            $context()->leaveJob();
        });

        $this->events()->listen(CommandFinished::class, function () use ($context, $flush): void {
            if ($context()->leaveCommand() && $this->app->runningInConsole()) {
                $flush();
            }
        });

        if (class_exists(self::OCTANE_REQUEST_TERMINATED)) {
            $this->events()->listen(self::OCTANE_REQUEST_TERMINATED, function () use ($context, $flush): void {
                $flush();
                $context()->reset();
            });
        }
    }

    private function meetsLogLevel(string $level): bool
    {
        $configured = strtolower((string) $this->app->make('config')->get('autoheal.log_level', 'error'));
        $threshold = array_search(in_array($configured, self::LOG_LEVELS, true) ? $configured : 'error', self::LOG_LEVELS, true);
        $rank = array_search(strtolower($level), self::LOG_LEVELS, true);

        return $rank !== false && $rank >= $threshold;
    }

    private function events(): Dispatcher
    {
        return $this->app->make('events');
    }
}
